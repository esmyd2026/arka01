<?php

namespace App\Http\Controllers;

use App\Models\FleetMember;
use App\Models\PlanPromotion;
use App\Models\PricingSetting;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionRequest;
use App\Models\User;
use App\Services\PlanLimits;
use App\Services\WhatsAppConfig;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Mi plan" (secciones 7, 7.2, 7.3 y 7.5): catálogo de planes, cupo usado del
 * plan vigente, historial de activaciones, y el pedido de plan en curso si
 * hay uno (consideración agregada al alcance: elegir plan + subir
 * comprobante). Sigue sin haber pasarela de pago (sección 7.5) — el que
 * activa la suscripción real es un admin, ver Admin\SubscriptionController.
 */
class MyPlanController extends Controller
{
    public function __construct(private readonly PlanLimits $planLimits) {}

    private function pendingRequestFor(int $userId, string $ownerType): ?SubscriptionRequest
    {
        $request = SubscriptionRequest::query()
            ->where('user_id', $userId)
            ->whereIn('status', ['awaiting_proof', 'pending_review', 'rejected'])
            ->whereHas('plan', fn ($query) => $query->where('owner_type', $ownerType))
            // Bug real reportado por el usuario: sin cargar `planPromotion`,
            // el panel de "Pedido de plan" no tenía cómo saber que este
            // pedido usaba una promoción, y siempre mostraba el precio de
            // LISTA del plan en vez del precio promocional que el usuario
            // efectivamente eligió.
            ->with(['plan', 'planPromotion', 'user'])
            ->latest()
            ->first();

        // Mismo criterio que la promoción de arriba, pero para el descuento
        // por cooperativa (pedido explícito del usuario) — solo aplica del
        // lado conductor, y solo si no hay promoción (la promoción gana).
        if ($request && $ownerType === 'driver' && ! $request->planPromotion) {
            $request->cooperative_discount = $this->planLimits->driverDiscountFor($request->plan, $request->user);
        }

        return $request;
    }

    /**
     * Historial completo de pedidos de plan — aprobados, rechazados y
     * pendientes (consideración agregada al alcance: "módulo de suscripción
     * con el detalle de mi plan y el historial de pagos").
     */
    private function requestHistoryFor(int $userId, string $ownerType): Collection
    {
        return SubscriptionRequest::query()
            ->where('user_id', $userId)
            ->whereHas('plan', fn ($query) => $query->where('owner_type', $ownerType))
            ->with(['plan', 'reviewedBy'])
            ->latest()
            ->get();
    }

    /**
     * Promoción vigente y elegible por plan (pedido explícito del usuario:
     * "que aparezca pagá tanto y ahorrá tanto") — la más barata si hubiera
     * más de una vigente a la vez para el mismo plan. `null` si no hay
     * ninguna, o si el usuario ya usó todas las que había.
     */
    private function attachActivePromotions(Collection $plans, User $user): Collection
    {
        $promotionsByPlan = PlanPromotion::visible()
            ->whereIn('subscription_plan_id', $plans->pluck('id'))
            ->orderBy('promo_price')
            ->get()
            ->groupBy('subscription_plan_id');

        $plans->each(function (SubscriptionPlan $plan) use ($promotionsByPlan, $user) {
            $eligible = ($promotionsByPlan->get($plan->id) ?? collect())
                ->first(fn (PlanPromotion $promotion) => $promotion->isEligibleFor($user));

            $plan->active_promotion = $eligible ? [
                'id' => $eligible->id,
                'label' => $eligible->label,
                'promo_price' => (float) $eligible->promo_price,
                'savings' => round((float) $plan->monthly_price - (float) $eligible->promo_price, 2),
                'ends_at' => $eligible->ends_at?->toDateString(),
            ] : null;
        });

        return $plans;
    }

    /**
     * Proyección de ganancia mensual por plan (pedido explícito del
     * usuario): "indiquemos en cada plan las carreras estimadas y un
     * estimado a ganar mensualmente" — carreras estimadas × ticket promedio,
     * ambos editables sin tocar código (SubscriptionPlan::estimated_monthly_rides
     * desde /admin/planes, PricingSetting::average_ticket_price desde
     * /admin/tarifas). Solo aplica a planes de conductor: un cliente no
     * "gana" nada por su plan.
     */
    private function attachEarningsProjection(Collection $plans): Collection
    {
        $ticket = (float) PricingSetting::current()->average_ticket_price;

        $plans->each(function (SubscriptionPlan $plan) use ($ticket) {
            $plan->earnings_projection = $plan->estimated_monthly_rides ? [
                'monthly_rides' => $plan->estimated_monthly_rides,
                'monthly_earnings' => round($plan->estimated_monthly_rides * $ticket, 2),
                'ticket' => $ticket,
            ] : null;
        });

        return $plans;
    }

    /**
     * Descuento por cooperativa (pedido explícito del usuario) — solo para
     * planes de conductor, y solo si ese plan no tiene ya una promoción
     * vigente (la promoción de precio fijo gana, para no mezclar los dos
     * criterios a la vez).
     */
    private function attachCooperativeDiscount(Collection $plans, User $driver): Collection
    {
        $plans->each(function (SubscriptionPlan $plan) use ($driver) {
            $plan->cooperative_discount = $plan->active_promotion ? null : $this->planLimits->driverDiscountFor($plan, $driver);
        });

        return $plans;
    }

    public function driver(Request $request): Response
    {
        $user = $request->user();
        $limits = $this->planLimits->forDriver($user);

        $activeClientCount = FleetMember::query()
            ->where('driver_user_id', $user->id)
            ->whereNull('left_at')
            ->count();

        // Solo planes activos, salvo que sea justo el que el usuario ya
        // tiene contratado (un plan discontinuado no debería desaparecer
        // de la vista de quien ya lo paga). Los de nivel inferior al
        // actual ni siquiera se mandan (sección 19: "no deberá visualizar
        // nuevamente los planes inferiores" — se filtra acá, no solo se
        // atenúa el botón en el frontend).
        $plans = SubscriptionPlan::query()
            ->where('owner_type', 'driver')
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('code', $limits['plan_code']))
            ->where('sort_order', '>=', $limits['plan_sort_order'])
            ->orderBy('sort_order')
            ->get();

        $plans = $this->attachEarningsProjection($this->attachActivePromotions($plans, $user));

        return Inertia::render('Plan/Driver', [
            'plans' => $this->attachCooperativeDiscount($plans, $user),
            'currentPlan' => $limits,
            'usedClients' => $activeClientCount,
            'changes' => $user->subscriptionChanges()
                ->whereHas('newPlan', fn ($query) => $query->where('owner_type', 'driver'))
                ->with(['oldPlan', 'newPlan'])
                ->latest()
                ->get(),
            'pendingRequest' => $this->pendingRequestFor($user->id, 'driver'),
            'requestHistory' => $this->requestHistoryFor($user->id, 'driver'),
        ]);
    }

    public function client(Request $request): Response
    {
        $user = $request->user();
        $limits = $this->planLimits->forClient($user);

        // Máximo de conductores en UNA SOLA flota (no la suma de todas) —
        // mismo cálculo que App\Services\SubscriptionPlanEligibility, para
        // poder atenuar "Elegir" en el frontend si un plan ya no alcanza.
        $maxDriversInAnyFleet = FleetMember::query()
            ->whereIn('fleet_id', $user->fleets()->pluck('id'))
            ->whereNull('left_at')
            ->selectRaw('fleet_id, count(*) as total')
            ->groupBy('fleet_id')
            ->get()
            ->max('total') ?? 0;

        // Mismo criterio que el catálogo de conductor: nunca se manda un
        // plan de nivel inferior al vigente (sección 19).
        $plans = SubscriptionPlan::query()
            ->where('owner_type', 'client')
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('code', $limits['plan_code']))
            ->where('sort_order', '>=', $limits['plan_sort_order'])
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('Plan/Client', [
            'plans' => $this->attachActivePromotions($plans, $user),
            'currentPlan' => $limits,
            'usedFleets' => $user->fleets()->count(),
            'maxDriversInAnyFleet' => $maxDriversInAnyFleet,
            'changes' => $user->subscriptionChanges()
                ->whereHas('newPlan', fn ($query) => $query->where('owner_type', 'client'))
                ->with(['oldPlan', 'newPlan'])
                ->latest()
                ->get(),
            'pendingRequest' => $this->pendingRequestFor($user->id, 'client'),
            'requestHistory' => $this->requestHistoryFor($user->id, 'client'),
        ]);
    }

    /**
     * "Mi plan" de cooperativa (pedido explícito del usuario: "dame los
     * beneficios de cada plan y muéstralo en los planes de cada
     * cooperativa") — no existía ninguna pantalla de catálogo/cambio de
     * plan para este rol, solo la etiqueta de solo lectura en
     * Cooperative/Profile.vue. Mismo patrón que client(), sin proyección de
     * ganancias (no aplica: la cooperativa no "gana" por su propio plan).
     */
    public function cooperative(Request $request): Response
    {
        $user = $request->user();
        $limits = $this->planLimits->forCooperative($user);

        $usedUnits = $user->cooperative?->activeDriverMemberships()->count() ?? 0;

        // Mismo criterio que los catálogos de conductor/cliente: nunca se
        // manda un plan de nivel inferior al vigente (sección 19), y un
        // plan discontinuado (ej. el ex "Empresarial") sigue viéndose para
        // quien ya lo tenía contratado.
        $plans = SubscriptionPlan::query()
            ->where('owner_type', 'cooperative')
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('code', $limits['plan_code']))
            ->where('sort_order', '>=', $limits['plan_sort_order'])
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('Plan/Cooperative', [
            'plans' => $this->attachActivePromotions($plans, $user),
            'currentPlan' => $limits,
            'usedUnits' => $usedUnits,
            'changes' => $user->subscriptionChanges()
                ->whereHas('newPlan', fn ($query) => $query->where('owner_type', 'cooperative'))
                ->with(['oldPlan', 'newPlan'])
                ->latest()
                ->get(),
            'pendingRequest' => $this->pendingRequestFor($user->id, 'cooperative'),
            'requestHistory' => $this->requestHistoryFor($user->id, 'cooperative'),
            // Tarjeta "Hablemos" para cooperativas grandes (pedido explícito
            // del usuario: "una opción de negociación" en vez de un plan de
            // precio fijo "sin límite") — sin número configurado, la
            // tarjeta simplemente no muestra el botón de contacto.
            'whatsappBusinessNumber' => WhatsAppConfig::businessNumber(),
        ]);
    }
}
