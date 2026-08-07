<?php

namespace App\Services;

use App\Models\FleetMember;
use App\Models\SubscriptionPlan;
use App\Models\User;

/**
 * Antes de dejar que alguien elija (o un admin apruebe) un plan, hay que
 * revisar dos cosas independientes:
 *
 * 1. Jerarquía (sección 19 de las directrices de arquitectura): las
 *    suscripciones son una progresión, nunca un retroceso — una vez que un
 *    usuario sube de plan, no puede volver a uno de nivel inferior ni
 *    siquiera al Gratis, sin importar si le alcanzaría el cupo. Se compara
 *    por `sort_order` (el "nivel"), nunca por nombre, para que agregar o
 *    reordenar planes a futuro no obligue a tocar esta lógica.
 * 2. Cupo (sección 9.6): "si hace downgrade y queda por encima del nuevo
 *    límite, no se expulsa a los clientes ya vinculados, pero se bloquean
 *    nuevas aceptaciones". Acá se lleva un paso más allá: directamente no se
 *    deja elegir un plan que ya no le alcanza con lo que tiene armado (esto
 *    en la práctica solo puede pasar entre planes del mismo nivel o
 *    superiores, ya el downgrade lo bloquea el punto 1).
 */
class SubscriptionPlanEligibility
{
    /**
     * Devuelve un mensaje explicando por qué NO entra, o null si sí entra.
     */
    public function reasonNotEligible(User $user, SubscriptionPlan $plan): ?string
    {
        if ($reason = $this->checkNotADowngrade($user, $plan)) {
            return $reason;
        }

        return $plan->owner_type === 'driver'
            ? $this->checkDriver($user, $plan)
            : $this->checkClient($user, $plan);
    }

    /**
     * Las suscripciones son una progresión, nunca un retroceso (sección 19):
     * si ya hay un plan activo de este lado y el elegido tiene un `sort_order`
     * menor, es un downgrade — no se permite, ni siquiera volviendo al Gratis.
     * Elegir el mismo plan que ya se tiene sí está permitido (no es downgrade).
     */
    private function checkNotADowngrade(User $user, SubscriptionPlan $plan): ?string
    {
        $currentPlan = $user->activeSubscription($plan->owner_type)?->plan;

        if ($currentPlan && $plan->sort_order < $currentPlan->sort_order) {
            return "Ya tiene el plan {$currentPlan->name} — no se puede bajar a un plan anterior ({$plan->name}). Puede mantener su plan actual o subir a uno superior.";
        }

        return null;
    }

    private function checkDriver(User $user, SubscriptionPlan $plan): ?string
    {
        if ($plan->max_clients === null) {
            return null;
        }

        $activeClients = FleetMember::query()
            ->where('driver_user_id', $user->id)
            ->whereNull('left_at')
            ->count();

        if ($activeClients > $plan->max_clients) {
            return "El plan {$plan->name} permite hasta {$plan->max_clients} clientes de confianza, pero ya tiene {$activeClients}. Saque a alguno primero o elija un plan con más cupo.";
        }

        return null;
    }

    private function checkClient(User $user, SubscriptionPlan $plan): ?string
    {
        if ($plan->max_fleets !== null) {
            $activeFleets = $user->fleets()->count();

            if ($activeFleets > $plan->max_fleets) {
                return "El plan {$plan->name} permite hasta {$plan->max_fleets} flota(s), pero ya tiene {$activeFleets}. Elija un plan con más cupo.";
            }
        }

        if ($plan->max_drivers_per_fleet !== null) {
            $fleetIds = $user->fleets()->pluck('id');

            $maxDriversInAnyFleet = FleetMember::query()
                ->whereIn('fleet_id', $fleetIds)
                ->whereNull('left_at')
                ->selectRaw('fleet_id, count(*) as total')
                ->groupBy('fleet_id')
                ->get()
                ->max('total') ?? 0;

            if ($maxDriversInAnyFleet > $plan->max_drivers_per_fleet) {
                return "El plan {$plan->name} permite hasta {$plan->max_drivers_per_fleet} conductores por flota, pero alguna de sus flotas ya tiene más. Saque a alguno primero o elija un plan con más cupo.";
            }
        }

        return null;
    }
}
