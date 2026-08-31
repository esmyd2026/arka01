<?php

namespace App\Services;

use App\Models\RadioChannel;
use App\Models\RadioChannelMember;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Administración de un canal de radio personal (renombrar, invitar,
 * expulsar, salir) — extraído de RadioChannelController (roadmap app
 * móvil, "full backend": nunca duplicar una regla de negocio entre web y
 * móvil).
 */
class RadioChannelManager
{
    private const MAX_MEMBERS = 30;

    public function join(RadioChannel $radioChannel, User $user): void
    {
        abort_unless($user->isClient() || $user->isDriver(), 403);
        abort_if($radioChannel->owner_user_id === $user->id, 422, 'Este ya es su canal principal.');

        if (! $radioChannel->members()->where('user_id', $user->id)->exists()) {
            abort_if($radioChannel->members()->count() >= self::MAX_MEMBERS, 409, 'El canal alcanzó su límite de integrantes.');

            $radioChannel->members()->create([
                'user_id' => $user->id,
                'joined_at' => now(),
            ]);
        }
    }

    public function rename(RadioChannel $radioChannel, User $user, string $name): void
    {
        $this->authorizeOwner($user, $radioChannel);

        $radioChannel->update(['name' => Str::squish($name)]);
    }

    public function rotateInvitation(RadioChannel $radioChannel, User $user): void
    {
        $this->authorizeOwner($user, $radioChannel);

        $radioChannel->rotateShareCode();
    }

    public function removeMember(RadioChannel $radioChannel, User $user, string $memberPublicId): void
    {
        $this->authorizeOwner($user, $radioChannel);

        $member = User::query()->where('public_id', $memberPublicId)->firstOrFail();
        $radioChannel->members()->where('user_id', $member->id)->delete();
    }

    public function leave(RadioChannel $radioChannel, User $user): void
    {
        RadioChannelMember::query()
            ->where('radio_channel_id', $radioChannel->id)
            ->where('user_id', $user->id)
            ->delete();
    }

    private function authorizeOwner(User $user, RadioChannel $radioChannel): void
    {
        abort_unless($radioChannel->owner_user_id === $user->id, 403);
    }
}
