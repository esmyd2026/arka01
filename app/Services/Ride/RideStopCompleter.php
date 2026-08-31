<?php

namespace App\Services\Ride;

use App\Events\RideCompleted;
use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\RideStop;
use App\Models\User;
use App\Notifications\RideCompletedPushNotification;
use App\Services\Haversine;
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

    public function complete(
        Ride $ride,
        RideStop $stop,
        User $actingUser,
        ?float $lat,
        ?float $lng,
        bool $cancelRest,
        ?string $completionReason = null,
        ?string $completionNote = null,
    ): void {
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

        // Pedido explícito del usuario: "debería permitir que termine [la
        // parada] como en el flujo normal, que si no está en el punto exacto
        // coloque un motivo y termine esa parada" — mismo criterio que
        // RideLifecycle::complete() con el destino final: sin motivo, lejos
        // del punto bloquea; con motivo, se acepta y queda guardado.
        $isNearStop = ! isset($lat, $lng) || Haversine::distanceKm(
            (float) $stop->lat,
            (float) $stop->lng,
            $lat,
            $lng,
        ) <= RideLifecycle::RIDE_ACTION_LOCATION_TOLERANCE_KM;

        if (! $isNearStop && ! $completionReason) {
            throw ValidationException::withMessages([
                'completion_reason' => 'Parece que todavía no está en la parada — elija un motivo para completarla igual.',
            ]);
        }

        $stop->update([
            'status' => 'completed',
            'completed_at' => now(),
            'completion_reason' => ! $isNearStop ? $completionReason : null,
            'completion_note' => ! $isNearStop ? $completionNote : null,
        ]);

        if ($cancelRest) {
            $ride->stops()
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            $settledPrice = (float) $ride->stops()->where('status', 'completed')->sum('leg_price');
            $ride->update([
                'status' => 'completed',
                'completed_at' => now(),
                'settled_price' => $settledPrice,
                'points_earned' => $ride->distance_km >= 5 ? 2 : 1,
            ]);

            DriverProfile::where('user_id', $ride->driver_user_id)->increment('total_points', $ride->points_earned);
            // Bug real reportado por el usuario ("la billetera no está
            // funcionando"): este cierre anticipado (cobrar y cancelar el
            // resto) nunca pasaba por RideLifecycle::complete(), así que
            // esas carreras de cooperativa se quedaban sin ningún
            // movimiento de billetera aunque sí quedaran cobradas.
            $this->rideLifecycle->recordCooperativeWalletEntry($ride, $settledPrice);
            broadcast(new RideCompleted($ride))->toOthers();
            RideDispatchAdvancer::activateNextWaitingRequest();
            $ride->client->notify(new RideCompletedPushNotification($ride));
            WhatsAppFreeformSender::sendRideCompletedToClient($ride);
        }
    }
}
