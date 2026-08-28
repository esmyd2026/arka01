<?php

namespace App\Services\Ride;

use App\Events\DriverLocationUpdated;
use App\Events\RideArrived;
use App\Events\RideCancelled;
use App\Events\RideCompleted;
use App\Events\RidePickedUp;
use App\Events\RideStarted;
use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\User;
use App\Notifications\RideArrivedPushNotification;
use App\Notifications\RideCancelledPushNotification;
use App\Notifications\RideCompletedPushNotification;
use App\Notifications\RidePickedUpPushNotification;
use App\Notifications\RideStartedPushNotification;
use App\Services\Haversine;
use App\Services\RideDispatchAdvancer;
use App\Services\WhatsAppFreeformSender;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ciclo de vida de una Ride ya aceptada — extraído de RideController
 * (roadmap app móvil, Hito 5: nunca duplicar una regla de negocio entre
 * web y móvil). Extracción literal, misma lógica que ya cubren
 * tests/Feature/Ride/RideRequestFlowTest.php, RideStopsTest.php y
 * RideTrackingBroadcastTest.php. No incluye reprogramación ni paradas
 * (`proposeReschedule`/`completeStop`) — el móvil todavía no soporta
 * carreras programadas ni multi-parada, quedan en el controlador web tal
 * cual hasta que el móvil las necesite.
 */
class RideLifecycle
{
    public const CLIENT_CANCEL_REASONS = [
        'Cambié de planes',
        'Encontré otro medio de transporte',
        'Pedí la carrera por error',
        'El conductor demoró demasiado',
        'Otro motivo',
    ];

    public const DRIVER_CANCEL_REASONS = [
        'Imprevisto personal',
        'Problema con el vehículo',
        'No voy a poder llegar a tiempo',
        'El cliente no responde o no aparece',
        'Motivo de seguridad',
        'Otro motivo',
    ];

    public const EARLY_COMPLETION_REASONS = [
        'El cliente pidió terminar el viaje antes de llegar',
        'El cliente no colocó la ubicación de destino correcta',
        'No se puede llegar hasta el punto exacto (acceso cerrado, obra, tráfico, etc.)',
        'Problema con el GPS del celular',
        'Otro motivo',
    ];

    private const RIDE_ACTION_LOCATION_TOLERANCE_KM = 1.5;

    private const AUTOMATIC_PICKUP_ARRIVAL_RADIUS_KM = 0.15;

    private const RIDE_COMPLETION_ARRIVAL_RADIUS_KM = 0.02;

    public function start(Ride $ride, User $actingUser): void
    {
        if ($ride->driver_user_id !== $actingUser->id) {
            abort(403);
        }

        if ($ride->status !== 'scheduled') {
            throw ValidationException::withMessages([
                'ride' => 'Esta carrera no está programada o ya arrancó.',
            ]);
        }

        if ($ride->hasPendingReschedule()) {
            throw ValidationException::withMessages([
                'ride' => 'El cliente propuso otro horario — confirmalo o rechazalo antes de arrancar.',
            ]);
        }

        $ride->update([
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        broadcast(new RideStarted($ride))->toOthers();

        $ride->client->notify(new RideStartedPushNotification($ride));
        WhatsAppFreeformSender::sendRideStartedToClient($ride);
    }

    public function headingToPassenger(Ride $ride, User $actingUser): void
    {
        if ($ride->driver_user_id !== $actingUser->id) {
            abort(403);
        }

        if ($ride->status !== 'in_progress') {
            throw ValidationException::withMessages([
                'ride' => 'Esta carrera no está en curso.',
            ]);
        }

        if ($ride->heading_to_passenger_at === null) {
            $ride->update(['heading_to_passenger_at' => now()]);
        }
    }

    public function arrived(Ride $ride, User $actingUser, ?float $lat, ?float $lng): void
    {
        if ($ride->driver_user_id !== $actingUser->id) {
            abort(403);
        }

        if ($ride->status !== 'in_progress') {
            throw ValidationException::withMessages([
                'ride' => 'Esta carrera no está en curso.',
            ]);
        }

        if ($ride->arrived_at !== null) {
            throw ValidationException::withMessages([
                'ride' => 'Ya se había marcado como llegada.',
            ]);
        }

        $this->assertNearRideLocation(
            $lat,
            $lng,
            (float) $ride->origin_lat,
            (float) $ride->origin_lng,
            'Parece que todavía no está en el punto de origen — inténtelo cuando esté más cerca.',
        );

        $ride->update(['arrived_at' => now()]);

        broadcast(new RideArrived($ride))->toOthers();

        $ride->client->notify(new RideArrivedPushNotification($ride));
        WhatsAppFreeformSender::sendRideArrivedToClient($ride);
    }

    public function pickedUp(Ride $ride, User $actingUser, ?float $lat, ?float $lng): void
    {
        if ($ride->driver_user_id !== $actingUser->id) {
            abort(403);
        }

        if ($ride->status !== 'in_progress') {
            throw ValidationException::withMessages([
                'ride' => 'Esta carrera no está en curso.',
            ]);
        }

        if ($ride->picked_up_at !== null) {
            throw ValidationException::withMessages([
                'ride' => 'Ya se había marcado como recogido.',
            ]);
        }

        $this->assertNearRideLocation(
            $lat,
            $lng,
            (float) $ride->origin_lat,
            (float) $ride->origin_lng,
            'Parece que todavía no está en el punto de origen — inténtelo cuando esté más cerca.',
        );

        $ride->update(['picked_up_at' => now()]);

        broadcast(new RidePickedUp($ride))->toOthers();

        $ride->client->notify(new RidePickedUpPushNotification($ride));
        WhatsAppFreeformSender::sendRidePickedUpToClient($ride);
    }

    public function complete(Ride $ride, User $actingUser, ?float $lat, ?float $lng, ?string $completionReason, ?string $completionNote): void
    {
        if ($ride->driver_user_id !== $actingUser->id) {
            abort(403);
        }

        if ($ride->status !== 'in_progress') {
            throw ValidationException::withMessages([
                'ride' => 'Esta carrera ya no está en curso.',
            ]);
        }

        if ($ride->stops()->whereNotIn('status', ['completed', 'cancelled'])->exists()) {
            throw ValidationException::withMessages([
                'ride' => 'Todavía queda una parada pendiente antes de completar la carrera.',
            ]);
        }

        $isNearDestination = ! isset($lat, $lng) || Haversine::distanceKm(
            (float) $ride->destination_lat,
            (float) $ride->destination_lng,
            $lat,
            $lng,
        ) <= self::RIDE_COMPLETION_ARRIVAL_RADIUS_KM;

        if (! $isNearDestination && ! $completionReason) {
            throw ValidationException::withMessages([
                'completion_reason' => 'Parece que todavía no llegó al destino — elija un motivo para completar la carrera igual.',
            ]);
        }

        $pointsEarned = $ride->distance_km >= 5 ? 2 : 1;

        $ride->update([
            'status' => 'completed',
            'completed_at' => now(),
            'points_earned' => $pointsEarned,
            'completion_reason' => $isNearDestination ? null : $completionReason,
            'completion_note' => $isNearDestination ? null : $completionNote,
            'settled_price' => $ride->stops()->where('status', 'completed')->sum('leg_price') + $ride->price,
        ]);

        DriverProfile::where('user_id', $ride->driver_user_id)->increment('total_points', $pointsEarned);

        broadcast(new RideCompleted($ride))->toOthers();

        RideDispatchAdvancer::activateNextWaitingRequest();

        $ride->client->notify(new RideCompletedPushNotification($ride));
        WhatsAppFreeformSender::sendRideCompletedToClient($ride);
    }

    public function cancel(Ride $ride, User $actingUser, string $reason, ?string $note): void
    {
        $isDriver = $ride->driver_user_id === $actingUser->id;

        if (! $isDriver && $ride->client_user_id !== $actingUser->id) {
            abort(403);
        }

        if (! in_array($ride->status, ['scheduled', 'in_progress'], true)) {
            throw ValidationException::withMessages([
                'ride' => 'Esta carrera ya no se puede cancelar.',
            ]);
        }

        // $reason ya viene validado por el controlador (Rule::in contra la
        // lista correcta según $isDriver) — mismo criterio que
        // RideRequestCreator::rules(), la validación vive en la capa HTTP,
        // no acá.
        $ride->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancelled_by' => $isDriver ? 'driver' : 'client',
            'cancellation_reason' => $reason,
            'cancellation_note' => $note,
        ]);

        broadcast(new RideCancelled($ride))->toOthers();

        $recipient = $isDriver ? $ride->client : $ride->driver;
        $recipient->notify(new RideCancelledPushNotification($ride));
    }

    /**
     * Ping de ubicación mientras se atiende ESTA carrera puntual — distinto
     * del switch general "Disponible" (App\Services\Driver\DriverAvailabilityUpdater).
     * Devuelve el `arrived_at` si la detección automática de llegada se
     * disparó con este ping, o null si no.
     */
    public function updateLocation(Ride $ride, User $actingUser, float $lat, float $lng): ?Carbon
    {
        if ((int) $ride->driver_user_id !== (int) $actingUser->id) {
            abort(403);
        }

        if ($ride->status !== 'in_progress') {
            throw ValidationException::withMessages([
                'ride' => 'Esta carrera ya no admite actualizaciones de ubicación.',
            ]);
        }

        $profile = $actingUser->driverProfile;

        if (! $profile) {
            abort(403, 'Todavía no activó su perfil de conductor.');
        }

        $profile->update([
            'current_lat' => $lat,
            'current_lng' => $lng,
            'location_updated_at' => now(),
        ]);

        $automaticallyArrived = DB::transaction(function () use ($ride, $lat, $lng) {
            $lockedRide = Ride::query()->lockForUpdate()->findOrFail($ride->id);

            if ($lockedRide->status !== 'in_progress' || $lockedRide->arrived_at || $lockedRide->picked_up_at) {
                return null;
            }

            $distanceToPickup = Haversine::distanceKm(
                (float) $lockedRide->origin_lat,
                (float) $lockedRide->origin_lng,
                $lat,
                $lng,
            );

            if ($distanceToPickup > self::AUTOMATIC_PICKUP_ARRIVAL_RADIUS_KM) {
                return null;
            }

            $lockedRide->update(['arrived_at' => now()]);

            return $lockedRide->fresh(['client', 'driver']);
        });

        broadcast(new DriverLocationUpdated($profile))->toOthers();

        if ($automaticallyArrived) {
            broadcast(new RideArrived($automaticallyArrived))->toOthers();
            $automaticallyArrived->client->notify(new RideArrivedPushNotification($automaticallyArrived));
            WhatsAppFreeformSender::sendRideArrivedToClient($automaticallyArrived);
        }

        return $automaticallyArrived?->arrived_at;
    }

    /**
     * Pública: RideController::completeStop() (paradas intermedias, todavía
     * sin extraer — el móvil no soporta multi-parada aún) también necesita
     * este mismo chequeo de proximidad, no solo los métodos de acá arriba.
     */
    public function assertNearRideLocation(?float $lat, ?float $lng, float $targetLat, float $targetLng, string $message): void
    {
        if ($lat === null || $lng === null) {
            return;
        }

        $distanceKm = Haversine::distanceKm($targetLat, $targetLng, $lat, $lng);

        if ($distanceKm > self::RIDE_ACTION_LOCATION_TOLERANCE_KM) {
            $distanceLabel = $distanceKm < 1
                ? round($distanceKm * 1000).' m'
                : number_format($distanceKm, 1).' km';

            throw ValidationException::withMessages([
                'ride' => $message.' Su ubicación está a '.$distanceLabel.' del punto requerido.',
            ]);
        }
    }
}
