<?php

namespace App\Services\Ride;

use App\Events\CooperativeRideUpdated;
use App\Events\RideRequested;
use App\Jobs\ExpireRideOffer;
use App\Jobs\ExpireWaitingRideRequest;
use App\Jobs\FallbackCooperativeAssignment;
use App\Models\ClientCooperative;
use App\Models\Cooperative;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\Ride;
use App\Models\RidePriceOffer;
use App\Models\RideRequest;
use App\Models\User;
use App\Notifications\CooperativeRideAssignedPushNotification;
use App\Notifications\CooperativeRideRequestedPushNotification;
use App\Notifications\RideRequestedPushNotification;
use App\Services\Haversine;
use App\Services\PriceCalculator;
use App\Services\RideDispatchCandidates;
use App\Services\SmartDispatchScorer;
use App\Services\WhatsAppFreeformSender;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Crea una solicitud de carrera (RideRequest) y arranca la negociación de
 * precio — extraído de RideRequestController::store() (roadmap app móvil,
 * Hito 5: nunca duplicar una regla de negocio entre web y móvil). Es una
 * extracción literal, no una reescritura: toda la cascada de validaciones
 * de conductor dirigido, el despacho secuencial estilo Uber, el cálculo de
 * distancia/precio por parada y los efectos posteriores (broadcast,
 * notificaciones, jobs de expiración) son exactamente los mismos que ya
 * verifica tests/Feature/Ride/*.
 */
class RideRequestCreator
{
    /** Ver RideRequestController::MIN_SCHEDULING_LEAD_HOURS. */
    private const MIN_SCHEDULING_LEAD_HOURS = 2;

    /** Ver RideRequestController::SCHEDULED_CONFLICT_BUFFER_MINUTES. */
    private const SCHEDULED_CONFLICT_BUFFER_MINUTES = 60;

    /** Ver RideRequestController::DIRECTED_REQUEST_TIMEOUT_SECONDS. */
    private const DIRECTED_REQUEST_TIMEOUT_SECONDS = 300;

    /**
     * Reglas de validación — un solo lugar para que web y móvil nunca
     * acepten un contrato distinto de campos.
     */
    public static function rules(): array
    {
        return [
            'fleet_id' => ['nullable', 'integer', 'exists:fleets,id'],
            'cooperative_id' => ['nullable', 'integer', 'exists:cooperatives,id'],
            'driver_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'origin_lat' => ['required', 'numeric', 'between:-90,90'],
            'origin_lng' => ['required', 'numeric', 'between:-180,180'],
            'origin_address' => ['nullable', 'string', 'max:255'],
            'origin_sector_id' => ['nullable', 'integer', 'exists:sectors,id'],
            'destination_lat' => ['required', 'numeric', 'between:-90,90'],
            'destination_lng' => ['required', 'numeric', 'between:-180,180'],
            'destination_address' => ['nullable', 'string', 'max:255'],
            'destination_sector_id' => ['nullable', 'integer', 'exists:sectors,id'],
            'route_distance_km' => ['nullable', 'numeric', 'min:0'],
            'offered_price' => ['nullable', 'numeric', 'min:0.01'],
            'is_scheduled' => ['sometimes', 'boolean'],
            'scheduled_date' => ['nullable', 'required_if:is_scheduled,1', 'date_format:Y-m-d'],
            'scheduled_time' => ['nullable', 'required_if:is_scheduled,1', 'date_format:H:i'],
            'round_trip' => ['sometimes', 'boolean'],
            'dispatch_pool' => ['nullable', 'in:fleet,public,both'],
            'passenger_count' => ['sometimes', 'integer', 'min:1', 'max:8'],
            'needs_trunk' => ['sometimes', 'boolean'],
            'payment_method' => ['sometimes', 'in:efectivo,transferencia'],
            'notes' => ['nullable', 'string', 'max:500'],
            'stops' => ['sometimes', 'array', 'max:4'],
            'stops.*.lat' => ['required_with:stops', 'numeric', 'between:-90,90'],
            'stops.*.lng' => ['required_with:stops', 'numeric', 'between:-180,180'],
            'stops.*.address' => ['nullable', 'string', 'max:255'],
            'stops.*.sector_id' => ['nullable', 'integer', 'exists:sectors,id'],
            'stops.*.route_distance_km' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function create(User $client, array $validated): RideRequest
    {
        $needsTrunk = (bool) ($validated['needs_trunk'] ?? false);
        $passengerCount = (int) ($validated['passenger_count'] ?? 1);

        $isScheduled = (bool) ($validated['is_scheduled'] ?? false);

        if (! $isScheduled) {
            $hasImmediateRequest = RideRequest::query()
                ->where('client_user_id', $client->id)
                ->where('is_scheduled', false)
                ->whereIn('status', ['pending', 'negotiating', 'waiting'])
                ->exists();
            $hasImmediateRide = Ride::query()
                ->where('client_user_id', $client->id)
                ->where('status', 'in_progress')
                ->exists();

            if ($hasImmediateRequest || $hasImmediateRide) {
                throw ValidationException::withMessages([
                    'ride' => 'Ya tiene una carrera inmediata activa. Debe finalizarla o cancelarla antes de solicitar otra.',
                ]);
            }
        }
        $scheduledAt = null;

        if ($isScheduled) {
            $scheduledAt = Carbon::createFromFormat(
                'Y-m-d H:i',
                "{$validated['scheduled_date']} {$validated['scheduled_time']}",
                config('app.timezone')
            );

            if ($scheduledAt->lessThan(now()->addHours(self::MIN_SCHEDULING_LEAD_HOURS))) {
                throw ValidationException::withMessages([
                    'scheduled_time' => 'La hora programada tiene que ser al menos '.self::MIN_SCHEDULING_LEAD_HOURS.' horas después de ahora.',
                ]);
            }
        }

        $fleet = $this->resolveFleet($client, $validated['fleet_id'] ?? null);
        $cooperative = null;

        if (! empty($validated['cooperative_id'])) {
            $cooperative = Cooperative::query()->findOrFail($validated['cooperative_id']);
            $isLinked = ClientCooperative::query()
                ->where('client_user_id', $client->id)
                ->where('cooperative_id', $cooperative->id)
                ->exists();

            if (! $isLinked || ! $cooperative->isApproved()) {
                throw ValidationException::withMessages([
                    'cooperative_id' => 'La cooperativa no pertenece a su red o todavía no está habilitada.',
                ]);
            }

            $validated['driver_user_id'] = null;
            $validated['dispatch_pool'] = null;
        }

        if (! $cooperative && ! empty($validated['driver_user_id'])) {
            $isActiveMember = $fleet->activeMembers()
                ->where('driver_user_id', $validated['driver_user_id'])
                ->exists();

            $isPublicDriver = DriverProfile::query()
                ->where('user_id', $validated['driver_user_id'])
                ->where('is_public', true)
                ->exists();

            if (! $isActiveMember && ! $isPublicDriver) {
                throw ValidationException::withMessages([
                    'driver_user_id' => 'Ese conductor no es parte de su flota ni está en el directorio público.',
                ]);
            }

            $chosenProfile = DriverProfile::query()->with('user')->where('user_id', $validated['driver_user_id'])->first();

            if ($chosenProfile?->isDeactivated()) {
                throw ValidationException::withMessages([
                    'driver_user_id' => 'Ese conductor pausó su perfil — no puede recibir solicitudes en este momento.',
                ]);
            }

            if (! $isScheduled && Ride::query()
                ->where('driver_user_id', $validated['driver_user_id'])
                ->where('status', 'in_progress')
                ->exists()) {
                throw ValidationException::withMessages([
                    'driver_user_id' => 'Ese conductor está atendiendo otra carrera. Elija otro conductor o solicite a toda su flota.',
                ]);
            }

            if ($chosenProfile && ! $chosenProfile->isWithinRangeOf((float) $validated['origin_lat'], (float) $validated['origin_lng'])) {
                throw ValidationException::withMessages([
                    'driver_user_id' => 'Ese conductor no recibe solicitudes tan lejos de su zona en este momento.',
                ]);
            }

            if ($chosenProfile && ! $chosenProfile->isReachable($chosenProfile->user?->hasActiveWhatsAppSession() ?? false)) {
                throw ValidationException::withMessages([
                    'driver_user_id' => 'Ese conductor parece haberse desconectado — pruebe con otro o con toda su flota.',
                ]);
            }

            $requestsDisabled = $fleet->activeMembers()
                ->where('driver_user_id', $validated['driver_user_id'])
                ->value('requests_disabled');

            if ($requestsDisabled) {
                throw ValidationException::withMessages([
                    'driver_user_id' => 'Ese conductor no está aceptando sus solicitudes en este momento.',
                ]);
            }

            if ($chosenProfile && $chosenProfile->passenger_capacity < $passengerCount) {
                throw ValidationException::withMessages([
                    'driver_user_id' => 'Ese conductor no tiene capacidad para esa cantidad de pasajeros.',
                ]);
            }

            if ($chosenProfile && $needsTrunk && ! $chosenProfile->has_trunk) {
                throw ValidationException::withMessages([
                    'driver_user_id' => 'Ese conductor no tiene cajuela disponible.',
                ]);
            }

            if ($isScheduled && $scheduledAt) {
                $hasConflict = Ride::query()
                    ->where('driver_user_id', $validated['driver_user_id'])
                    ->where('status', 'scheduled')
                    ->whereHas('rideRequest', function ($query) use ($scheduledAt) {
                        $query->whereBetween('scheduled_at', [
                            $scheduledAt->clone()->subMinutes(self::SCHEDULED_CONFLICT_BUFFER_MINUTES),
                            $scheduledAt->clone()->addMinutes(self::SCHEDULED_CONFLICT_BUFFER_MINUTES),
                        ]);
                    })
                    ->exists();

                if ($hasConflict) {
                    throw ValidationException::withMessages([
                        'scheduled_time' => 'Ese conductor ya tiene otra carrera programada cerca de ese horario.',
                    ]);
                }
            }
        }

        $driverUserId = $validated['driver_user_id'] ?? null;
        $dispatchPool = null;
        $offerCandidateIds = [];
        $currentOfferExpiresAt = null;
        $requestStatus = 'pending';

        $cooperativeCandidateIds = [];
        $cooperativeOfferExpiresAt = null;
        $cooperativeAssignmentStatus = null;

        if ($cooperative) {
            $cooperativeAssignmentStatus = $cooperative->automatic_assignment_enabled ? 'pending_assignment' : 'awaiting_operator';

            if (! $isScheduled && $cooperative->automatic_assignment_enabled) {
                $candidateIds = RideDispatchCandidates::forCooperative(
                    $cooperative,
                    (float) $validated['origin_lat'],
                    (float) $validated['origin_lng'],
                    $passengerCount,
                    $needsTrunk,
                );

                if (! empty($candidateIds)) {
                    $driverUserId = array_shift($candidateIds);
                    $offerCandidateIds = $candidateIds;
                    $cooperativeCandidateIds = $candidateIds;
                    $dispatchPool = 'cooperative';
                    $timeoutSeconds = max(15, min(300, (int) $cooperative->response_timeout_seconds));
                    $currentOfferExpiresAt = now()->addSeconds($timeoutSeconds);
                    $cooperativeOfferExpiresAt = $currentOfferExpiresAt;
                    $cooperativeAssignmentStatus = 'awaiting_driver';
                }
            }
        } elseif (! $driverUserId && ! $isScheduled) {
            $dispatchPool = $validated['dispatch_pool'] ?? 'fleet';

            $candidateIds = RideDispatchCandidates::forPool(
                $fleet,
                $client,
                $dispatchPool,
                (float) $validated['origin_lat'],
                (float) $validated['origin_lng'],
                $passengerCount,
                $needsTrunk,
            );

            if (empty($candidateIds)) {
                if (RideDispatchCandidates::isEmptyOnlyBecauseEveryoneIsBusy(
                    $fleet,
                    $client,
                    $dispatchPool,
                    (float) $validated['origin_lat'],
                    (float) $validated['origin_lng'],
                    $passengerCount,
                    $needsTrunk,
                )) {
                    $requestStatus = 'waiting';
                } else {
                    $reason = RideDispatchCandidates::explainEmptyPool(
                        $fleet,
                        $client,
                        $dispatchPool,
                        (float) $validated['origin_lat'],
                        (float) $validated['origin_lng'],
                        $passengerCount,
                        $needsTrunk,
                    );

                    throw ValidationException::withMessages(['driver_user_id' => $reason]);
                }
            } else {
                $driverUserId = array_shift($candidateIds);
                $offerCandidateIds = $candidateIds;
                $currentOfferExpiresAt = now()->addSeconds(30);
            }
        } elseif ($driverUserId && ! $isScheduled) {
            $currentOfferExpiresAt = now()->addSeconds(self::DIRECTED_REQUEST_TIMEOUT_SECONDS);
        }

        $stopsInput = $validated['stops'] ?? [];

        $finalLegOriginLat = $stopsInput ? (float) end($stopsInput)['lat'] : (float) $validated['origin_lat'];
        $finalLegOriginLng = $stopsInput ? (float) end($stopsInput)['lng'] : (float) $validated['origin_lng'];

        $haversineKm = round(Haversine::distanceKm(
            $finalLegOriginLat,
            $finalLegOriginLng,
            (float) $validated['destination_lat'],
            (float) $validated['destination_lng'],
        ), 2);

        $routeDistanceKm = $validated['route_distance_km'] ?? null;
        $distanceKm = ($routeDistanceKm !== null && $routeDistanceKm >= $haversineKm * 0.95 && $routeDistanceKm <= $haversineKm * 5)
            ? round((float) $routeDistanceKm, 2)
            : $haversineKm;

        $ratePerKm = $cooperative
            ? (float) DriverProfile::query()
                ->whereIn('user_id', $cooperative->activeDriverMemberships()->pluck('driver_user_id'))
                ->whereNotNull('rate_per_km')
                ->avg('rate_per_km')
            : $this->referenceRatePerKm($fleet, $driverUserId);
        $driverMinimumFareForStops = $this->referenceMinimumFare($driverUserId);

        // Cargo por trayecto de recogida (pedido explícito del usuario): solo
        // tiene sentido para el candidato puntual que va a recibir la oferta
        // primero — sin conductor resuelto todavía (bolsa vacía que quedó
        // "waiting", o cooperativa esperando asignación manual) no hay a
        // quién calcularle la distancia, queda en null.
        $pickupSurcharge = $driverUserId
            ? PriceCalculator::pickupSurchargeForDriver($driverUserId, (float) $validated['origin_lat'], (float) $validated['origin_lng'])
            : ['distance_km' => null, 'fare' => null];

        $stopsData = [];
        $stopsPrice = 0.0;
        $previousLat = (float) $validated['origin_lat'];
        $previousLng = (float) $validated['origin_lng'];

        foreach ($stopsInput as $index => $stopInput) {
            $legHaversineKm = round(Haversine::distanceKm($previousLat, $previousLng, (float) $stopInput['lat'], (float) $stopInput['lng']), 2);
            $legRouteDistanceKm = $stopInput['route_distance_km'] ?? null;
            $legDistanceKm = ($legRouteDistanceKm !== null && $legRouteDistanceKm >= $legHaversineKm * 0.95 && $legRouteDistanceKm <= $legHaversineKm * 5)
                ? round((float) $legRouteDistanceKm, 2)
                : $legHaversineKm;

            $legPrice = PriceCalculator::suggestedPrice($legDistanceKm, $ratePerKm, driverMinimumFare: $driverMinimumFareForStops)['total'];
            $stopsPrice += $legPrice;

            $stopsData[] = [
                'sequence' => $index + 1,
                'lat' => $stopInput['lat'],
                'lng' => $stopInput['lng'],
                'address' => $stopInput['address'] ?? null,
                'sector_id' => $stopInput['sector_id'] ?? null,
                'leg_distance_km' => $legDistanceKm,
                'leg_price' => $legPrice,
            ];

            $previousLat = (float) $stopInput['lat'];
            $previousLng = (float) $stopInput['lng'];
        }

        $stopsPrice = $stopsData ? PriceCalculator::roundUpToDime($stopsPrice) : null;

        $suggestedPrice = PriceCalculator::suggestedPrice(
            $distanceKm,
            $ratePerKm,
            driverMinimumFare: $this->referenceMinimumFare($driverUserId),
        )['total'];

        if (isset($validated['offered_price']) && $validated['offered_price'] < $suggestedPrice) {
            throw ValidationException::withMessages([
                'offered_price' => 'Su propuesta no puede ser menor al precio estimado ($'.number_format($suggestedPrice, 2).').',
            ]);
        }

        $offeredPrice = round((float) ($validated['offered_price'] ?? $suggestedPrice), 2);

        $smartDispatchVersion = null;
        $smartDispatchSnapshot = null;
        if ($dispatchPool && $driverUserId && ! $isScheduled && config('smart_dispatch.enabled', true)) {
            try {
                $smartDispatchVersion = SmartDispatchScorer::VERSION;
                $smartDispatchSnapshot = SmartDispatchScorer::safeSnapshot(
                    [$driverUserId, ...$offerCandidateIds],
                    (float) $validated['origin_lat'],
                    (float) $validated['origin_lng'],
                );
            } catch (\Throwable $exception) {
                Log::warning('No se pudo guardar la auditoría del despacho inteligente.', [
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        $rideRequest = DB::transaction(function () use (
            $validated, $fleet, $client, $distanceKm, $offeredPrice, $isScheduled, $scheduledAt,
            $driverUserId, $dispatchPool, $offerCandidateIds, $currentOfferExpiresAt, $needsTrunk, $passengerCount, $requestStatus,
            $cooperative, $cooperativeCandidateIds, $cooperativeOfferExpiresAt, $cooperativeAssignmentStatus,
            $smartDispatchVersion, $smartDispatchSnapshot, $stopsPrice, $stopsData, $pickupSurcharge,
        ) {
            $rideRequest = RideRequest::query()->create([
                'fleet_id' => $fleet->id,
                'cooperative_id' => $cooperative?->id,
                'cooperative_assignment_status' => $cooperativeAssignmentStatus,
                'cooperative_candidate_ids' => $cooperativeCandidateIds ?: null,
                'cooperative_offer_expires_at' => $cooperativeOfferExpiresAt,
                'client_user_id' => $client->id,
                'driver_user_id' => $driverUserId,
                'origin_lat' => $validated['origin_lat'],
                'origin_lng' => $validated['origin_lng'],
                'origin_address' => $validated['origin_address'] ?? null,
                'origin_sector_id' => $validated['origin_sector_id'] ?? null,
                'destination_lat' => $validated['destination_lat'],
                'destination_lng' => $validated['destination_lng'],
                'destination_address' => $validated['destination_address'] ?? null,
                'destination_sector_id' => $validated['destination_sector_id'] ?? null,
                'distance_km' => $distanceKm,
                'pickup_distance_km' => $pickupSurcharge['distance_km'],
                'pickup_fare' => $pickupSurcharge['fare'],
                'payment_method' => $validated['payment_method'] ?? 'efectivo',
                'status' => $requestStatus,
                'current_offered_price' => $offeredPrice,
                'stops_price' => $stopsPrice,
                'negotiation_round' => 0,
                'last_offer_made_by' => 'client',
                'requested_at' => now(),
                'is_scheduled' => $isScheduled,
                'scheduled_at' => $scheduledAt,
                'round_trip' => (bool) ($validated['round_trip'] ?? false),
                'dispatch_pool' => $dispatchPool,
                'offer_candidate_ids' => $offerCandidateIds ?: null,
                'current_offer_expires_at' => $currentOfferExpiresAt,
                'smart_dispatch_version' => $smartDispatchVersion,
                'smart_dispatch_snapshot' => $smartDispatchSnapshot,
                'passenger_count' => $passengerCount,
                'needs_trunk' => $needsTrunk,
                'notes' => $validated['notes'] ?? null,
            ]);

            RidePriceOffer::query()->create([
                'ride_request_id' => $rideRequest->id,
                'offered_by_user_id' => $client->id,
                'offered_amount' => $offeredPrice,
            ]);

            foreach ($stopsData as $stopData) {
                $rideRequest->stops()->create($stopData);
            }

            return $rideRequest;
        });

        Log::info('Carrera solicitada.', [
            'ride_request_id' => $rideRequest->id,
            'fleet_id' => $fleet->id,
            'cooperative_id' => $cooperative?->id,
            'client_user_id' => $rideRequest->client_user_id,
            'driver_user_id' => $rideRequest->driver_user_id,
            'distance_km' => $distanceKm,
            'offered_price' => $offeredPrice,
        ]);

        if ($rideRequest->cooperative_id) {
            broadcast(new CooperativeRideUpdated($rideRequest, 'created'));
            $rideRequest->cooperative->user->notify(new CooperativeRideRequestedPushNotification($rideRequest));
        }

        if ($rideRequest->status === 'waiting') {
            Log::info('Solicitud quedó en espera: todos los conductores elegibles están ocupados ahora mismo.', [
                'ride_request_id' => $rideRequest->id,
            ]);

            ExpireWaitingRideRequest::dispatch($rideRequest->id)->delay(now()->addMinutes(15));

            return $rideRequest;
        }

        if ($rideRequest->cooperative_id && ! $rideRequest->driver_user_id) {
            if (! $rideRequest->is_scheduled) {
                FallbackCooperativeAssignment::dispatch($rideRequest->id)
                    ->delay(now()->addSeconds((int) ($cooperative->manual_assignment_timeout_seconds ?? 30)));
            }

            return $rideRequest;
        }

        broadcast(new RideRequested($rideRequest))->toOthers();
        $this->notifyDriversOfNewRequest($rideRequest, $fleet);
        if ($rideRequest->cooperative_id && $rideRequest->driver_user_id) {
            $rideRequest->client->notify(new CooperativeRideAssignedPushNotification($rideRequest));
        }

        if ($rideRequest->current_offer_expires_at && $rideRequest->dispatch_pool) {
            ExpireRideOffer::dispatch($rideRequest->id, $rideRequest->driver_user_id)
                ->delay($rideRequest->current_offer_expires_at);
        }

        return $rideRequest;
    }

    /**
     * Pública: RideRequestController::create() (pantalla web) también
     * necesita resolver la flota antes de armar el roster, no solo store().
     */
    public function resolveFleet(User $client, ?int $fleetId): Fleet
    {
        if ($fleetId) {
            return Fleet::query()
                ->where('owner_user_id', $client->id)
                ->findOrFail($fleetId);
        }

        return Fleet::query()
            ->where('owner_user_id', $client->id)
            ->orderBy('id')
            ->first()
            ?? Fleet::query()->create([
                'owner_user_id' => $client->id,
                'name' => 'Mi flota',
            ]);
    }

    private function referenceRatePerKm(Fleet $fleet, ?int $driverUserId): float
    {
        if ($driverUserId) {
            return (float) (User::find($driverUserId)?->driverProfile?->rate_per_km ?? 0);
        }

        $rates = $fleet->activeMembers()
            ->with('driver.driverProfile')
            ->get()
            ->map(fn ($member) => (float) ($member->driver->driverProfile?->rate_per_km ?? 0))
            ->filter();

        return $rates->isEmpty() ? 0.0 : round($rates->avg(), 2);
    }

    private function referenceMinimumFare(?int $driverUserId): ?float
    {
        if (! $driverUserId) {
            return null;
        }

        $minimumFare = User::find($driverUserId)?->driverProfile?->minimum_fare;

        return $minimumFare !== null ? (float) $minimumFare : null;
    }

    private function notifyDriversOfNewRequest(RideRequest $rideRequest, Fleet $fleet): void
    {
        $driverIds = $rideRequest->driver_user_id
            ? [$rideRequest->driver_user_id]
            : $fleet->activeMembers()->where('requests_disabled', false)->pluck('driver_user_id')->all();

        User::query()->whereIn('id', $driverIds)->with('driverProfile')->get()
            ->filter(fn (User $driver) => $driver->driverProfile?->isWithinRangeOf(
                (float) $rideRequest->origin_lat,
                (float) $rideRequest->origin_lng,
            ) ?? true)
            ->each(function (User $driver) use ($rideRequest) {
                $driver->notify(new RideRequestedPushNotification($rideRequest));
                WhatsAppFreeformSender::sendNewRideAlert($driver, $rideRequest);
            });
    }
}
