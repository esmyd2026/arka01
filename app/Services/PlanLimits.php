<?php

namespace App\Services;

use App\Models\SubscriptionPlan;
use App\Models\User;

/**
 * Única fuente de verdad para "¿cuál es mi límite y qué funciones tengo
 * habilitadas?" (sección 9.6). Los controladores no deben leer las tablas de
 * suscripción directamente: siempre pasan por acá, para que cambiar de dónde
 * sale el dato no obligue a tocarlos.
 *
 * Resolución en cascada, de más a menos específico:
 *   1) Suscripción activa/en gracia del usuario (con sus overrides "custom_*"
 *      para el plan Institucional, cuyo cupo se pacta por convenio).
 *   2) El plan "Gratis" correspondiente, tal como está en la base de datos.
 *      No hay un tercer nivel de respaldo en código: la fila "gratis" de cada
 *      lado la siembra la propia migración de subscription_plans y el panel
 *      /admin/planes no permite borrarla — siempre existe.
 */
class PlanLimits
{
    /**
     * Límites y funciones del rol conductor: cuántos clientes de confianza
     * puede tener y si aparece en el directorio público / con prioridad /
     * con insignia de verificado (sección 7.2).
     *
     * @return array{plan_code: string, plan_name: string, plan_sort_order: int, max_clients: int|null, public_visibility: bool, priority_listing: bool, verified_badge: bool, van_trips_enabled: bool, express_enabled: bool, subscription_status: string|null, expires_at: string|null}
     */
    public function forDriver(User $user): array
    {
        $subscription = $user->activeSubscription('driver');
        $plan = $subscription?->plan ?? $this->freePlan('driver');

        return [
            'plan_code' => $plan->code,
            'plan_name' => $plan->name,
            // Nivel jerárquico del plan (sección 19 de las directrices de
            // arquitectura: nunca comparar planes por nombre — un downgrade se
            // detecta comparando este número, ver SubscriptionPlanEligibility).
            'plan_sort_order' => $plan->sort_order,
            // null = sin límite (Institucional sin cupo pactado en el convenio).
            'max_clients' => $subscription?->custom_max_clients ?? $plan->max_clients,
            'public_visibility' => (bool) $plan->public_visibility,
            'priority_listing' => (bool) $plan->priority_listing,
            'verified_badge' => (bool) $plan->verified_badge,
            // Módulo de viajes tipo VAN/turismo (pedido explícito del
            // usuario): "plan premium exclusivo para conductores".
            'van_trips_enabled' => (bool) $plan->van_trips_enabled,
            // Módulo de Expresos (pedido explícito del usuario: poder
            // habilitarlo o no por plan) — ver ExpressRouteController::available()
            // y ExpressApplicationController::store().
            'express_enabled' => (bool) $plan->express_enabled,
            // Pedido explícito del usuario: mostrar estado y vencimiento de la
            // suscripción — antes no se veía en ningún lado. null = plan
            // Gratis (no hay una suscripción de verdad detrás, no vence nunca).
            'subscription_status' => $subscription?->status,
            'expires_at' => $subscription?->expires_at?->toIso8601String(),
        ];
    }

    /**
     * Límites del rol cliente: cuántas flotas puede tener y cuántos
     * conductores por flota (sección 7.3).
     *
     * @return array{plan_code: string, plan_name: string, plan_sort_order: int, max_fleets: int|null, max_drivers_per_fleet: int|null, subscription_status: string|null, expires_at: string|null}
     */
    public function forClient(User $user): array
    {
        $subscription = $user->activeSubscription('client');
        $plan = $subscription?->plan ?? $this->freePlan('client');

        return [
            'plan_code' => $plan->code,
            'plan_name' => $plan->name,
            'plan_sort_order' => $plan->sort_order,
            'max_fleets' => $subscription?->custom_max_fleets ?? $plan->max_fleets,
            'max_drivers_per_fleet' => $subscription?->custom_max_drivers_per_fleet ?? $plan->max_drivers_per_fleet,
            'subscription_status' => $subscription?->status,
            'expires_at' => $subscription?->expires_at?->toIso8601String(),
        ];
    }

    private function freePlan(string $ownerType): SubscriptionPlan
    {
        return SubscriptionPlan::query()
            ->where('owner_type', $ownerType)
            ->where('code', 'gratis')
            ->firstOrFail();
    }
}
