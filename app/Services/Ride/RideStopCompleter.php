<?php

namespace App\Services\Ride;

use App\Events\RideCompleted;
use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\RideStop;
use App\Models\User;
use App\Notifications\RideCompletedPushNotification;
use App\Services\RideDispatchAdvancer;
use App\Services\WhatsAppFreeformSender;
use Illuminate\Validation\ValidationException;

/**
 * Completa una parada intermedia — extraído de RideController::completeStop()
 * (roadmap app móvil, Hito 5: nunca duplicar una regla de negocio entre web
 * y móvil). Con `cancelRest`, cierra la carrera entera ahí mismo (cobrando
 * solo lo completado).
 */
class RideStopCompleter
{
    public function __construct(private readonly RideLifecycle $rideLifecycle) {}

    public function complete(Ride $ride, RideStop $stop, User $actingUser, ?float $lat, ?float $lng, bool $cancelRest): void
    {
        if ($ride->driver_user_id !== $actingUser->id) {
            abort(403);
        }

        if ($stop->ride_id !== $ride->id) {
            abort(404);
        }

        if ($ride->status !== 'in_progress' || ! $ride->picked_up_at) {
            throw ValidationException::withMessages([
                'ride' => 'Esta carrera no está en curso, o todavía no se recogió al cliente.',
            ]);
        }

        $nextPendingStop = $ride->stops()->whereNotIn('status', ['completed', 'cancelled'])->first();
        if (! $nextPendingStop || $nextPendingStop->id !== $stop->id) {
            throw ValidationException::withMessages([
                'ride' => 'Esta no es la próxima parada pendiente.',
            ]);
        }

        $this->rideLifecycle->assertNearRideLocation(
            $lat,
            $lng,
            (float) $stop->lat,
            (float) $stop->lng,
            'Parece que todavía no está en la parada — inténtelo cuando esté más cerca.',
        );

        $stop->update(['status' => 'completed', 'completed_at' => now()]);

        if ($cancelRest) {
            $ride->stops()
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            $ride->update([
                'status' => 'completed',
                'completed_at' => now(),
                'settled_price' => $ride->stops()->where('status', 'completed')->sum('leg_price'),
                'points_earned' => $ride->distance_km >= 5 ? 2 : 1,
            ]);

            DriverProfile::where('user_id', $ride->driver_user_id)->increment('total_points', $ride->points_earned);
            broadcast(new RideCompleted($ride))->toOthers();
            RideDispatchAdvancer::activateNextWaitingRequest();
            $ride->client->notify(new RideCompletedPushNotification($ride));
            WhatsAppFreeformSender::sendRideCompletedToClient($ride);
        }
    }
}
