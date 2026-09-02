<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Cooperative;
use App\Models\Fleet;
use App\Models\LandingCtaEvent;
use App\Models\Ride;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Indicadores básicos del negocio (sección 9.5-C): cuántos usuarios hay en
 * cada plan (para "saber quién es quién" — quién paga, quién no, y cuánto
 * factura cada categoría) y una estimación de ingreso mensual recurrente.
 */
class MetricsController extends Controller
{
    public function index(): Response
    {
        $driverPlans = $this->planBreakdown('driver');
        $clientPlans = $this->planBreakdown('client');
        // Pedido explícito del usuario: "no tengo indicadores de las
        // cooperativas" — mismo desglose por plan que ya existía para
        // conductor y cliente, del lado de la cooperativa.
        $cooperativePlans = $this->planBreakdown('cooperative');

        $mrr = $driverPlans->sum('monthly_total') + $clientPlans->sum('monthly_total') + $cooperativePlans->sum('monthly_total');

        return Inertia::render('Admin/Metrics', [
            'driverPlans' => $driverPlans,
            'clientPlans' => $clientPlans,
            'cooperativePlans' => $cooperativePlans,
            'estimatedMrr' => round($mrr, 2),
            'landingCta' => $this->landingCtaMetrics(),
            'totals' => [
                'users' => User::query()->count(),
                'drivers' => User::query()->whereHas('driverProfile')->count(),
                'clients' => User::query()->whereHas('fleets')->count(),
                'fleets' => Fleet::query()->count(),
                'completedRides' => Ride::query()->where('status', 'completed')->count(),
                'cooperatives' => Cooperative::query()->count(),
                'approvedCooperatives' => Cooperative::query()->where('status', 'approved')->count(),
            ],
        ]);
    }

    /**
     * Embudo de la portada. Se separan eventos totales de visitantes únicos:
     * un doble clic no debe aparentar que llegaron dos personas diferentes.
     */
    private function landingCtaMetrics(): array
    {
        $since = now()->subDays(30);
        $events = LandingCtaEvent::query()
            ->where('created_at', '>=', $since)
            ->get(['event_type', 'visitor_hash', 'created_at']);
        $impressions = $events->where('event_type', 'impression');
        $clicks = $events->where('event_type', 'click');

        $uniqueVisitors = $impressions->pluck('visitor_hash')->unique()->count();
        $uniqueClicks = $clicks->pluck('visitor_hash')->unique()->count();

        $daily = collect(range(13, 0))->map(function (int $daysAgo) use ($impressions, $clicks) {
            $day = now()->subDays($daysAgo);
            $date = $day->toDateString();

            return [
                'date' => $date,
                'label' => $day->format('d/m'),
                'impressions' => $impressions->filter(fn (LandingCtaEvent $event) => $event->created_at->toDateString() === $date)->pluck('visitor_hash')->unique()->count(),
                'clicks' => $clicks->filter(fn (LandingCtaEvent $event) => $event->created_at->toDateString() === $date)->pluck('visitor_hash')->unique()->count(),
            ];
        });

        return [
            'unique_visitors_30d' => $uniqueVisitors,
            'unique_clicks_30d' => $uniqueClicks,
            'clicks_today' => $clicks->filter(fn (LandingCtaEvent $event) => $event->created_at->isToday())->count(),
            'clicks_7d' => $clicks->filter(fn (LandingCtaEvent $event) => $event->created_at->gte(now()->subDays(7)))->count(),
            'conversion_rate' => $uniqueVisitors > 0 ? round(($uniqueClicks / $uniqueVisitors) * 100, 1) : 0,
            'daily' => $daily,
            'max_daily' => max((int) $daily->max('impressions'), 1),
        ];
    }

    /**
     * Para cada plan de un lado (driver o cliente): cuántos usuarios tienen
     * una suscripción vigente de ese plan puntual, y cuánto suman en ingreso
     * mensual. Los que no tienen ninguna suscripción vigente cuentan como
     * Gratis — mismo criterio que PlanLimits (sección 9.6).
     *
     * @return Collection<int, array{code: string, name: string, monthly_price: float, subscriber_count: int, monthly_total: float}>
     */
    private function planBreakdown(string $ownerType)
    {
        $plans = SubscriptionPlan::query()
            ->where('owner_type', $ownerType)
            ->orderBy('sort_order')
            ->get();

        // Usuarios con una suscripción vigente (activa o en gracia) de este
        // lado, agrupados por plan — para no contar dos veces si alguien
        // tuviera más de una fila histórica.
        // Rendimiento de cara a producción (pedido explícito del usuario):
        // esto SÍ era un N+1 real — `activeSubscription()` dispara una
        // consulta nueva por cada usuario, a pesar de que la relación de
        // abajo ya trae exactamente esos mismos datos precargados (y ya
        // ordenados por `latest('started_at')`, así que el primero de la
        // colección es el mismo que devolvería `activeSubscription()`).
        $subscriberCounts = User::query()
            ->whereHas('subscriptions', fn ($query) => $query
                ->whereIn('status', ['active', 'grace'])
                ->whereHas('plan', fn ($query) => $query->where('owner_type', $ownerType))
            )
            ->with(['subscriptions' => fn ($query) => $query
                ->whereIn('status', ['active', 'grace'])
                ->whereHas('plan', fn ($query) => $query->where('owner_type', $ownerType))
                ->latest('started_at'),
            ])
            ->get()
            ->countBy(fn (User $user) => $user->subscriptions->first()?->subscription_plan_id);

        // El universo base de este lado: conductores (con perfil), clientes
        // (con al menos una flota), o cooperativas (con cuenta propia) —
        // así el plan Gratis también muestra cuántos hay, no solo los que pagan.
        $baseCount = match ($ownerType) {
            'driver' => User::query()->whereHas('driverProfile')->count(),
            'cooperative' => User::query()->whereHas('cooperative')->count(),
            default => User::query()->whereHas('fleets')->count(),
        };

        $paidSubscriberTotal = $subscriberCounts->sum();

        return $plans->map(function (SubscriptionPlan $plan) use ($subscriberCounts, $baseCount, $paidSubscriberTotal) {
            $isFree = $plan->code === 'gratis';
            $subscriberCount = $isFree
                ? max($baseCount - $paidSubscriberTotal, 0)
                : ($subscriberCounts[$plan->id] ?? 0);

            return [
                'code' => $plan->code,
                'name' => $plan->name,
                'monthly_price' => (float) $plan->monthly_price,
                'subscriber_count' => $subscriberCount,
                'monthly_total' => round($subscriberCount * (float) $plan->monthly_price, 2),
            ];
        });
    }
}
