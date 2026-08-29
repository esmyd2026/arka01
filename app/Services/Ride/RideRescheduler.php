<?php

namespace App\Services\Ride;

use App\Events\RideRescheduleProposed;
use App\Events\RideRescheduleResponded;
use App\Models\Ride;
use App\Models\User;
use App\Notifications\RideReschedulePushNotification;
use App\Notifications\RideRescheduleResponsePushNotification;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Reprogramación de una carrera programada — extraído de RideController
 * (`proposeReschedule`/`confirmReschedule`/`rejectReschedule`, roadmap app
 * móvil, Hito 5: nunca duplicar una regla de negocio entre web y móvil).
 * No se aplica sola: el conductor ya se comprometió al horario original,
 * queda pendiente hasta que confirme o rechace el nuevo.
 */
class RideRescheduler
{
    private const MIN_LEAD_MESSAGE = 'La fecha y hora tiene que ser en el futuro.';

    public function propose(Ride $ride, User $actingUser, string $scheduledDate, string $scheduledTime): void
    {
        if ($ride->client_user_id !== $actingUser->id) {
            abort(403);
        }

        if ($ride->status !== 'scheduled') {
            throw ValidationException::withMessages([
                'ride' => 'Esta carrera no está programada.',
            ]);
        }

        $proposedAt = Carbon::createFromFormat(
            'Y-m-d H:i',
            "{$scheduledDate} {$scheduledTime}",
            config('app.timezone')
        );

        if ($proposedAt->isPast()) {
            throw ValidationException::withMessages([
                'scheduled_time' => self::MIN_LEAD_MESSAGE,
            ]);
        }

        $ride->update(['pending_reschedule_at' => $proposedAt]);

        broadcast(new RideRescheduleProposed($ride))->toOthers();

        $ride->driver->notify(new RideReschedulePushNotification($ride));
    }

    public function confirm(Ride $ride, User $actingUser): void
    {
        if ($ride->driver_user_id !== $actingUser->id) {
            abort(403);
        }

        if (! $ride->hasPendingReschedule()) {
            throw ValidationException::withMessages([
                'ride' => 'No hay ningún cambio de horario pendiente.',
            ]);
        }

        $ride->rideRequest->update(['scheduled_at' => $ride->pending_reschedule_at]);
        $ride->update(['pending_reschedule_at' => null]);

        broadcast(new RideRescheduleResponded($ride, true))->toOthers();

        $ride->client->notify(new RideRescheduleResponsePushNotification($ride, true));
    }

    public function reject(Ride $ride, User $actingUser): void
    {
        if ($ride->driver_user_id !== $actingUser->id) {
            abort(403);
        }

        if (! $ride->hasPendingReschedule()) {
            throw ValidationException::withMessages([
                'ride' => 'No hay ningún cambio de horario pendiente.',
            ]);
        }

        $ride->update(['pending_reschedule_at' => null]);

        broadcast(new RideRescheduleResponded($ride, false))->toOthers();

        $ride->client->notify(new RideRescheduleResponsePushNotification($ride, false));
    }
}
