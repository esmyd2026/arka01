<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una flota con su roster completo, tal como lo arma
 * App\Services\Fleet\FleetRosterBuilder — mismo cálculo que usa la web
 * (Fleet/List.vue), acá aplanado a JSON para el cliente móvil.
 *
 * `$this->resource` es el array `['fleet' => Fleet, 'memberStats' => Collection]`
 * que devuelve el builder, no el modelo Fleet directo.
 */
class FleetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $fleet = $this->resource['fleet'];
        $memberStats = $this->resource['memberStats'];

        return [
            'id' => $fleet->id,
            'name' => $fleet->name,
            'members' => $fleet->activeMembers->map(function ($member) use ($memberStats) {
                $driver = $member->driver;
                $stats = $memberStats->get($driver->id, []);

                return [
                    // PK de FleetMember, no del usuario — es lo que espera
                    // DELETE /fleet/members/{member} (igual que la web usa
                    // member.id, no member.driver.id, para quitar a alguien).
                    'member_id' => $member->id,
                    'user_id' => $driver->public_id,
                    'driver_user_id' => $driver->id,
                    'name' => $driver->full_name,
                    'avatar_url' => $driver->avatar_url,
                    'rate_per_km' => $driver->driverProfile?->rate_per_km,
                    'is_available' => (bool) $driver->driverProfile?->is_available,
                    'average_rating' => $stats['average_rating'] ?? null,
                    'review_count' => $stats['review_count'] ?? 0,
                    'rides_count' => $stats['rides_count'] ?? 0,
                    'active_clients_count' => $stats['active_clients_count'] ?? 0,
                    'tier' => $stats['tier'] ?? null,
                    'tier_label' => $stats['tier']['name'] ?? null,
                ];
            })->values(),
            'pending_invitations' => $fleet->invitations->map(fn ($invitation) => [
                'id' => $invitation->id,
                'driver_name' => $invitation->driver->full_name,
                'invited_by_name' => $invitation->inviter?->full_name,
                'initiated_by' => $invitation->initiated_by,
            ])->values(),
        ];
    }
}
