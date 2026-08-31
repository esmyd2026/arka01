<?php

namespace App\Services\Ride;

use App\Events\RideRequestAccepted;
use App\Events\RideRequestCancelled;
use App\Events\RideRequestCountered;
use App\Events\RideRequestDeclined;
use App\Events\RideRequestPriceRaised;
use App\Models\Ride;
use App\Models\RidePriceOffer;
use App\Models\RideRequest;
use App\Models\User;
use App\Notifications\RideAcceptedPushNotification;
use App\Notifications\RideRequestCounteredPushNotification;
use App\Notifications\RideRequestDeclinedPushNotification;
use App\Services\RideDispatchAdvancer;
use App\Services\WhatsAppFreeformSender;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Responde a una RideRequest ya creada (aceptar, contraofertar, subir
 * oferta, rechazar, cancelar) — extraído de RideRequestController (roadmap
 * app móvil, Hito 5: nunca duplicar una regla de negocio entre web y
 * móvil). Extracción literal, misma lógica que ya verifica
 * tests/Feature/Ride/*.
 */
class RideRequestResponder
{
    public function accept(RideRequest $rideRequest, User $actingUser): Ride
    {
        $userId = $actingUser->id;

        if ($rideRequest->status === 'negotiating') {
            if ($rideRequest->client_user_id !== $userId) {
                abort(403);
            }
            $driverId = $rideRequest->negotiating_driver_user_id;
        } else {
            if ($rideRequest->isDirected() && $rideRequest->driver_user_id !== $userId) {
                abort(403);
            }

            if (! $rideRequest->isDirected()) {
                $isActiveMember = $rideRequest->fleet->activeMembers()
                    ->where('driver_user_id', $userId)
                    ->exists();

                if (! $isActiveMember) {
                    abort(403);
                }
            }

            $driverId = $userId;
        }

        $ride = DB::transaction(function () use ($rideRequest, $driverId) {
            $locked = RideRequest::query()->lockForUpdate()->findOrFail($rideRequest->id);

            if (! in_array($locked->status, ['pending', 'negotiating'], true)) {
                throw ValidationException::withMessages([
                    'ride_request' => 'Esta solicitud ya no está disponible.',
                ]);
            }

            User::query()->lockForUpdate()->findOrFail($driverId);

            if (! $locked->is_scheduled && Ride::query()
                ->where('driver_user_id', $driverId)
                ->where('status', 'in_progress')
                ->exists()) {
                throw ValidationException::withMessages([
                    'ride_request' => 'Ya tiene una carrera en curso. Termine o cancele ese viaje antes de aceptar otro.',
                ]);
            }

            $ratePerKm = User::find($driverId)?->driverProfile?->rate_per_km ?? 0;

            // Cargo por trayecto de recogida (pedido explícito del usuario):
            // ya viene incluido en `current_offered_price` desde que se creó
            // la solicitud (ver RideRequestCreator::create()) — aceptar la
            // solicitud implica aceptar ese precio completo, sin un paso
            // aparte para "sumarlo o no". `pickup_fare_charged` queda como
            // trazabilidad de que sí se cobró (para indicadores).
            $pickupFareCharged = (float) ($locked->pickup_fare ?? 0) > 0;

            $ride = Ride::query()->create([
                'ride_request_id' => $locked->id,
                'fleet_id' => $locked->fleet_id,
                'client_user_id' => $locked->client_user_id,
                'driver_user_id' => $driverId,
                'origin_lat' => $locked->origin_lat,
                'origin_lng' => $locked->origin_lng,
                'origin_address' => $locked->origin_address,
                'origin_sector_id' => $locked->origin_sector_id,
                'destination_lat' => $locked->destination_lat,
                'destination_lng' => $locked->destination_lng,
                'destination_address' => $locked->destination_address,
                'destination_sector_id' => $locked->destination_sector_id,
                'distance_km' => $locked->distance_km,
                'pickup_distance_km' => $locked->pickup_distance_km,
                'pickup_fare' => $locked->pickup_fare,
                'pickup_fare_charged' => $pickupFareCharged,
                'payment_method' => $locked->payment_method,
                'notes' => $locked->notes,
                'round_trip' => $locked->round_trip,
                'rate_per_km_snapshot' => $ratePerKm,
                'price' => $locked->current_offered_price,
                'stops_price' => $locked->stops_price,
                'status' => $locked->is_scheduled ? 'scheduled' : 'in_progress',
                'started_at' => $locked->is_scheduled ? null : now(),
            ]);

            foreach ($locked->stops as $stop) {
                $ride->stops()->create([
                    'sequence' => $stop->sequence,
                    'lat' => $stop->lat,
                    'lng' => $stop->lng,
                    'address' => $stop->address,
                    'sector_id' => $stop->sector_id,
                    'leg_distance_km' => $stop->leg_distance_km,
                    'leg_price' => $stop->leg_price,
                    'status' => 'pending',
                ]);
            }

            $locked->update([
                'status' => 'accepted',
                'accepted_by' => $driverId,
                'responded_at' => now(),
                'cooperative_assignment_status' => $locked->cooperative_id ? 'accepted' : $locked->cooperative_assignment_status,
                'cooperative_offer_expires_at' => null,
            ]);

            return $ride;
        });

        Log::info('Carrera aceptada.', [
            'ride_request_id' => $rideRequest->id,
            'ride_id' => $ride->id,
            'driver_user_id' => $driverId,
        ]);

        broadcast(new RideRequestAccepted($rideRequest->fresh(), $ride->id))->toOthers();

        $ride->client->notify(new RideAcceptedPushNotification($ride));
        WhatsAppFreeformSender::sendRideAcceptedToClient($ride);

        return $ride;
    }

    public function counter(RideRequest $rideRequest, User $actingUser, float $offeredAmount): void
    {
        $userId = $actingUser->id;

        if ($rideRequest->cooperative_id) {
            throw ValidationException::withMessages([
                'offered_amount' => 'La cooperativa ya definió el pago de esta carrera. Puede aceptarla o rechazarla, pero no cambiar la tarifa al cliente.',
            ]);
        }

        if ($rideRequest->isDirected() && $rideRequest->driver_user_id !== $userId) {
            abort(403);
        }

        if (! $rideRequest->isDirected()) {
            $isActiveMember = $rideRequest->fleet->activeMembers()
                ->where('driver_user_id', $userId)
                ->exists();

            if (! $isActiveMember) {
                abort(403);
            }
        }

        DB::transaction(function () use ($rideRequest, $userId, $offeredAmount) {
            $locked = RideRequest::query()->lockForUpdate()->findOrFail($rideRequest->id);

            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages([
                    'ride_request' => 'Esta solicitud ya no está disponible para contraofertar.',
                ]);
            }

            $locked->update([
                'status' => 'negotiating',
                'current_offered_price' => round($offeredAmount, 2),
                'negotiation_round' => 1,
                'last_offer_made_by' => 'driver',
                'negotiating_driver_user_id' => $userId,
            ]);

            RidePriceOffer::query()->create([
                'ride_request_id' => $locked->id,
                'offered_by_user_id' => $userId,
                'offered_amount' => round($offeredAmount, 2),
            ]);
        });

        Log::info('Conductor contraofertó.', [
            'ride_request_id' => $rideRequest->id,
            'driver_user_id' => $userId,
            'offered_amount' => round($offeredAmount, 2),
        ]);

        $updatedRequest = $rideRequest->fresh(['client', 'negotiatingDriver']);

        broadcast(new RideRequestCountered($updatedRequest))->toOthers();
        $updatedRequest->client->notify(new RideRequestCounteredPushNotification(
            $updatedRequest->id,
            $updatedRequest->negotiatingDriver->name,
            (float) $updatedRequest->current_offered_price,
        ));
    }

    public function raiseOffer(RideRequest $rideRequest, User $actingUser, float $offeredAmount): void
    {
        if ($rideRequest->client_user_id !== $actingUser->id) {
            abort(403);
        }

        DB::transaction(function () use ($rideRequest, $actingUser, $offeredAmount) {
            $locked = RideRequest::query()->lockForUpdate()->findOrFail($rideRequest->id);

            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages([
                    'ride_request' => 'Esta solicitud ya no está pendiente.',
                ]);
            }

            if ($offeredAmount <= (float) $locked->current_offered_price) {
                throw ValidationException::withMessages([
                    'offered_amount' => 'El nuevo monto tiene que ser mayor al que ya ofreció.',
                ]);
            }

            $locked->update(['current_offered_price' => round($offeredAmount, 2)]);

            RidePriceOffer::query()->create([
                'ride_request_id' => $locked->id,
                'offered_by_user_id' => $actingUser->id,
                'offered_amount' => round($offeredAmount, 2),
            ]);
        });

        Log::info('Cliente subió su oferta.', [
            'ride_request_id' => $rideRequest->id,
            'client_user_id' => $actingUser->id,
            'offered_amount' => round($offeredAmount, 2),
        ]);

        broadcast(new RideRequestPriceRaised($rideRequest->fresh()))->toOthers();
    }

    public function reject(RideRequest $rideRequest, User $actingUser): void
    {
        if ($rideRequest->driver_user_id !== $actingUser->id) {
            abort(403);
        }

        if ($rideRequest->status !== 'pending') {
            throw ValidationException::withMessages([
                'ride_request' => 'Esta solicitud ya no está pendiente.',
            ]);
        }

        Log::info('Conductor rechazó la solicitud.', [
            'ride_request_id' => $rideRequest->id,
            'driver_user_id' => $actingUser->id,
        ]);

        $actingUser->driverProfile?->increment('rides_rejected_count');

        if ($rideRequest->isSequentialDispatch()) {
            RideDispatchAdvancer::advanceOrExpire($rideRequest->id, $actingUser->id, 'rejected');

            return;
        }

        $driverName = $actingUser->name;

        $rideRequest->update(['status' => 'cancelled', 'responded_at' => now()]);

        broadcast(new RideRequestCancelled($rideRequest))->toOthers();

        broadcast(new RideRequestDeclined($rideRequest, $driverName));
        $rideRequest->client->notify(new RideRequestDeclinedPushNotification($rideRequest, $driverName));
    }

    public function cancel(RideRequest $rideRequest, User $actingUser): void
    {
        if ($rideRequest->client_user_id !== $actingUser->id) {
            abort(403);
        }

        if (! in_array($rideRequest->status, ['pending', 'negotiating', 'waiting'], true)) {
            throw ValidationException::withMessages([
                'ride_request' => 'Esta solicitud ya no está pendiente.',
            ]);
        }

        $rideRequest->update(['status' => 'cancelled', 'responded_at' => now()]);

        Log::info('Cliente canceló la solicitud.', [
            'ride_request_id' => $rideRequest->id,
            'client_user_id' => $actingUser->id,
        ]);

        broadcast(new RideRequestCancelled($rideRequest))->toOthers();
    }
}
