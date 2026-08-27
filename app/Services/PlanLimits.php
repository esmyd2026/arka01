<?php

namespace App\Services;

use App\Models\CooperativeDriverMembership;
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
            'max_cooperatives' => $subscription?->custom_max_cooperatives ?? $plan->max_cooperatives,
            'subscription_status' => $subscription?->status,
            'expires_at' => $subscription?->expires_at?->toIso8601String(),
        ];
    }

    /** Límites parametrizables del plan de una cooperativa. */
    public function forCooperative(User $user): array
    {
        $subscription = $user->activeSubscription('cooperative');
        $plan = $subscription?->plan ?? $this->freePlan('cooperative');

        return [
            'plan_code' => $plan->code,
            'plan_name' => $plan->name,
            'plan_sort_order' => $plan->sort_order,
            'max_units' => $subscription?->custom_max_units ?? $plan->max_units,
            // Descuento que este plan de cooperativa le da a SU conductor
            // afiliado en el plan INDIVIDUAL del conductor (pedido explícito
            // del usuario) — ver cooperativeDriverDiscountPercent() abajo,
            // que es quien realmente lo aplica.
            'driver_discount_percent' => (int) $plan->driver_discount_percent,
            'subscription_status' => $subscription?->status,
            'expires_at' => $subscription?->expires_at?->toIso8601String(),
        ];
    }

    /**
     * Descuento (0-100) que le corresponde HOY a este conductor en su propio
     * plan individual, por estar afiliado a una cooperativa activa y
     * aprobada (pedido explícito del usuario: "si un conductor... es de una
     * cooperativa... ella paga su parte por cantidad de conductores... el
     * conductor puede tener un descuento"). Se recalcula siempre desde la
     * relación viva, nunca se guarda — mismo criterio que las promociones de
     * PlanPromotion: si el conductor deja la cooperativa, el descuento deja
     * de aplicar solo.
     */
    public function cooperativeDriverDiscountPercent(User $driver): int
    {
        $membership = CooperativeDriverMembership::query()
            ->where('driver_user_id', $driver->id)
            ->where('status', 'accepted')
            ->whereNull('ended_at')
            ->whereNull('suspended_at')
            ->with('cooperative.user')
            ->first();

        if (! $membership || ! $membership->cooperative->isApproved()) {
            return 0;
        }

        return $this->forCooperative($membership->cooperative->user)['driver_discount_percent'];
    }

    /**
     * Forma completa del descuento (porcentaje + precio ya calculado) para
     * un plan de conductor puntual — única fuente de esto, reusada por
     * MyPlanController (catálogo y pedido pendiente) y
     * Admin\SubscriptionController (monto esperado por el admin al revisar
     * un comprobante).
     *
     * @return array{percent: int, discounted_price: float}|null
     */
    public function driverDiscountFor(SubscriptionPlan $plan, User $driver): ?array
    {
        $percent = $this->cooperativeDriverDiscountPercent($driver);

        return $percent > 0 ? [
            'percent' => $percent,
            'discounted_price' => round((float) $plan->monthly_price * (1 - $percent / 100), 2),
        ] : null;
    }

    /**
     * Pedido explícito del usuario: "cuando una cooperativa tenga que
     * buscar a un conductor, y pueda permanecer en su cooperativa activo,
     * tiene que tener el plan mayor al gratis, y tiene que estar vigente" —
     * true solo cuando el conductor tiene una suscripción pagada realmente
     * activa o en gracia. No hace falta mirar `subscription_status` aparte:
     * si la suscripción venció/se canceló, User::activeSubscription() ya
     * deja de devolverla y forDriver() cae solo al plan Gratis (código
     * 'gratis') — con eso alcanza para saber "está vigente y no es gratis".
     */
    public function hasActivePaidPlan(User $driver): bool
    {
        return $this->forDriver($driver)['plan_code'] !== 'gratis';
    }

    private function freePlan(string $ownerType): SubscriptionPlan
    {
        return SubscriptionPlan::query()
            ->where('owner_type', $ownerType)
            ->where('code', 'gratis')
            ->firstOrFail();
    }
}
