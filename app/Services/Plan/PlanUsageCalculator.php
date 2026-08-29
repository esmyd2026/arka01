<?php

namespace App\Services\Plan;

use App\Models\FleetMember;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Cupo usado del plan vigente y su historial de cambios — extraído de
 * MyPlanController (roadmap app móvil, "full backend": nunca duplicar una
 * regla de negocio entre web y móvil). No incluye el catálogo de planes ni
 * el flujo de "pedir un cambio de plan" (comprobante, revisión de admin,
 * promociones/cupones) — eso sigue siendo solo de la web, deliberadamente
 * fuera del alcance de esta pasada.
 */
class PlanUsageCalculator
{
    public function activeClientCountForDriver(User $driver): int
    {
        return FleetMember::query()
            ->where('driver_user_id', $driver->id)
            ->whereNull('left_at')
            ->count();
    }

    public function usedFleetsForClient(User $client): int
    {
        return $client->fleets()->count();
    }

    /**
     * Máximo de conductores en UNA SOLA flota del cliente (no la suma de
     * todas) — mismo cálculo que App\Services\SubscriptionPlanEligibility,
     * para poder avisar si un plan ya no alcanza.
     */
    public function maxDriversInAnyFleetForClient(User $client): int
    {
        return FleetMember::query()
            ->whereIn('fleet_id', $client->fleets()->pluck('id'))
            ->whereNull('left_at')
            ->selectRaw('fleet_id, count(*) as total')
            ->groupBy('fleet_id')
            ->get()
            ->max('total') ?? 0;
    }

    public function usedUnitsForCooperative(User $cooperative): int
    {
        return $cooperative->cooperative?->activeDriverMemberships()->count() ?? 0;
    }

    /**
     * Historial de cambios de plan efectivos (activados por un admin), del
     * lado indicado (`driver`/`client`/`cooperative`) — no confundir con el
     * historial de PEDIDOS de cambio (`SubscriptionRequest`), que sigue
     * siendo solo de la web.
     */
    public function changesFor(User $user, string $ownerType): Collection
    {
        return $user->subscriptionChanges()
            ->whereHas('newPlan', fn ($query) => $query->where('owner_type', $ownerType))
            ->with(['oldPlan', 'newPlan'])
            ->latest()
            ->get();
    }
}
