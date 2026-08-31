<?php

namespace App\Services\Cooperative;

use App\Models\CooperativeDriverMembership;
use App\Models\User;
use App\Notifications\CooperativeDriverResponsePushNotification;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * El conductor decide si acepta o rechaza un vínculo con una cooperativa —
 * extraído de CooperativeDriverController (roadmap app móvil, "full
 * backend": nunca duplicar una regla de negocio entre web y móvil).
 */
class CooperativeDriverResponder
{
    public function pendingInvitations(User $driver): Collection
    {
        return $driver->cooperativeDriverMemberships()
            ->whereIn('status', ['pending', 'accepted', 'suspended'])
            ->with('cooperative.city')
            ->latest()
            ->get();
    }

    public function respond(CooperativeDriverMembership $membership, User $driver, string $decision): void
    {
        abort_unless($membership->driver_user_id === $driver->id, 403);
        abort_unless($membership->status === 'pending', 422);

        $validator = validator(['decision' => $decision], ['decision' => ['required', 'string', Rule::in(['accept', 'reject'])]]);
        $validator->validate();

        $accepted = $decision === 'accept';

        if ($accepted) {
            $alreadyActive = CooperativeDriverMembership::query()
                ->where('driver_user_id', $driver->id)
                ->where('status', 'accepted')
                ->whereNull('ended_at')
                ->where('id', '!=', $membership->id)
                ->exists();

            if ($alreadyActive) {
                throw ValidationException::withMessages(['membership' => 'Ya pertenece a otra cooperativa activa. Primero debe finalizar ese vínculo.']);
            }
        }

        $membership->forceFill([
            'status' => $accepted ? 'accepted' : 'rejected',
            'responded_at' => now(),
            'suspended_at' => null,
            'ended_at' => $accepted ? null : now(),
        ])->save();

        if ($accepted) {
            $driver->driverProfile->forceFill(['driver_type' => 'public_transport'])->save();
        }

        $membership->load(['driver', 'cooperative.user']);
        $membership->cooperative->user->notify(new CooperativeDriverResponsePushNotification($membership, $accepted));
    }
}
