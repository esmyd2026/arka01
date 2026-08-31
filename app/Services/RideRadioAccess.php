<?php

namespace App\Services;

use App\Models\RadioChannel;
use App\Models\RadioChannelMember;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Resuelve canales personales autorizados y temporalmente activos.
 *
 * La membresía del círculo es persistente, pero una sala solo se entrega si
 * su propietario está solicitando o realizando una carrera. El navegador
 * puede elegir entre los resultados autorizados, nunca inventar una sala.
 */
class RideRadioAccess
{
    public function __construct(private readonly RadioAccessToken $tokens) {}

    /** @return array<string, mixed>|null */
    public function resolve(User $user, ?string $channelPublicId = null): ?array
    {
        $channels = $this->resolveAll($user);

        return $channelPublicId
            ? $channels->firstWhere('public_id', $channelPublicId)
            : $channels->first();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function resolveAll(User $user): Collection
    {
        if (! $user->isClient() && ! $user->isDriver()) {
            return collect();
        }

        $contexts = collect();
        $activeRide = $this->activeRide($user);

        // Canal temporal entre cliente y conductor. Va primero para que sea
        // la opción inicial al comenzar una carrera, sin reemplazar los
        // canales personales que cada uno comparte con su círculo.
        if ($activeRide) {
            $contexts->push($this->rideContext($activeRide, $user));
        }

        $ownPhase = $this->activePhase($user);

        if ($ownPhase) {
            $ownChannel = RadioChannel::query()->firstOrCreate(
                ['owner_user_id' => $user->id],
                ['name' => 'Canal de '.($user->full_name ?: $user->name)],
            );
            $ownChannel->load(['owner:id,public_id,name,last_name,role', 'members.user:id,public_id,name,last_name,role']);
            $contexts->push($this->context($ownChannel, $user, $ownPhase));
        }

        $memberships = RadioChannelMember::query()
            ->where('user_id', $user->id)
            ->with(['channel.owner:id,public_id,name,last_name,role', 'channel.members.user:id,public_id,name,last_name,role'])
            ->get();

        foreach ($memberships as $membership) {
            $channel = $membership->channel;
            if (! $channel?->owner) {
                continue;
            }

            $phase = $this->activePhase($channel->owner);
            if ($phase) {
                $contexts->push($this->context($channel, $user, $phase));
            }
        }

        return $contexts->unique('public_id')->values();
    }

    private function activeRide(User $user): ?Ride
    {
        return Ride::query()
            ->where($user->isDriver() ? 'driver_user_id' : 'client_user_id', $user->id)
            ->where('status', 'in_progress')
            ->with(['client:id,public_id,name,last_name,role', 'driver:id,public_id,name,last_name,role'])
            ->latest('started_at')
            ->first();
    }

    private function activePhase(User $owner): ?string
    {
        if (! $owner->isClient() && ! $owner->isDriver()) {
            return null;
        }

        $hasActiveRide = Ride::query()
            ->where($owner->isDriver() ? 'driver_user_id' : 'client_user_id', $owner->id)
            ->where('status', 'in_progress')
            ->exists();

        if ($hasActiveRide) {
            return 'active';
        }

        if (! $owner->isClient()) {
            return null;
        }

        $isSearching = RideRequest::query()
            ->where('client_user_id', $owner->id)
            ->where('is_scheduled', false)
            ->whereIn('status', ['pending', 'negotiating', 'waiting'])
            ->whereNotNull('origin_lat')
            ->whereNotNull('origin_lng')
            ->exists();

        return $isSearching ? 'searching' : null;
    }

    /** @return array<string, mixed> */
    private function context(RadioChannel $channel, User $viewer, string $phase): array
    {
        $isOwner = $channel->owner_user_id === $viewer->id;
        $members = $channel->members->map(fn (RadioChannelMember $membership) => [
            'public_id' => $membership->user->public_id,
            'name' => $membership->user->full_name ?: $membership->user->name,
            'role' => $membership->user->isDriver() ? 'conductor' : 'cliente',
        ])->values();

        return [
            'kind' => 'circle',
            'public_id' => $channel->public_id,
            'room_id' => $this->tokens->roomForChannel($channel->public_id),
            'phase' => $phase,
            'label' => $channel->name,
            'owner' => [
                'public_id' => $channel->owner->public_id,
                'name' => $channel->owner->full_name ?: $channel->owner->name,
                'role' => $channel->owner->isDriver() ? 'conductor' : 'cliente',
            ],
            'is_owner' => $isOwner,
            'invite_url' => $isOwner ? route('radio.invitation.show', $channel->share_code) : null,
            'member_count' => $members->count(),
            'members' => $members,
        ];
    }

    /** @return array<string, mixed> */
    private function rideContext(Ride $ride, User $viewer): array
    {
        $counterpart = $ride->client_user_id === $viewer->id ? $ride->driver : $ride->client;
        $participants = collect([$ride->client, $ride->driver])
            ->filter()
            ->map(fn (User $participant) => [
                'public_id' => $participant->public_id,
                'name' => $participant->full_name ?: $participant->name,
                'role' => $participant->isDriver() ? 'conductor' : 'cliente',
            ])
            ->values();

        return [
            'kind' => 'ride',
            'public_id' => $ride->public_id,
            'room_id' => $this->tokens->roomForRideRequest((int) $ride->ride_request_id),
            'phase' => 'active',
            'label' => 'Hablar con '.($counterpart?->full_name ?: $counterpart?->name ?: 'la otra persona'),
            'owner' => $counterpart ? [
                'public_id' => $counterpart->public_id,
                'name' => $counterpart->full_name ?: $counterpart->name,
                'role' => $counterpart->isDriver() ? 'conductor' : 'cliente',
            ] : null,
            'is_owner' => false,
            'invite_url' => null,
            'member_count' => $participants->count(),
            'members' => $participants,
        ];
    }
}
