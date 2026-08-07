<?php

namespace App\Http\Controllers;

use App\Models\FleetMember;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionRequest;
use App\Services\PlanLimits;
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
        return SubscriptionRequest::query()
            ->where('user_id', $userId)
            ->whereIn('status', ['awaiting_proof', 'pending_review', 'rejected'])
            ->whereHas('plan', fn ($query) => $query->where('owner_type', $ownerType))
            ->with('plan')
            ->latest()
            ->first();
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

    public function driver(Request $request): Response
    {
        $user = $request->user();
        $limits = $this->planLimits->forDriver($user);

        $activeClientCount = FleetMember::query()
            ->where('driver_user_id', $user->id)
            ->whereNull('left_at')
            ->count();

        return Inertia::render('Plan/Driver', [
            // Solo planes activos, salvo que sea justo el que el usuario ya
            // tiene contratado (un plan discontinuado no debería desaparecer
            // de la vista de quien ya lo paga). Los de nivel inferior al
            // actual ni siquiera se mandan (sección 19: "no deberá visualizar
            // nuevamente los planes inferiores" — se filtra acá, no solo se
            // atenúa el botón en el frontend).
            'plans' => SubscriptionPlan::query()
                ->where('owner_type', 'driver')
                ->where(fn ($query) => $query->where('is_active', true)->orWhere('code', $limits['plan_code']))
                ->where('sort_order', '>=', $limits['plan_sort_order'])
                ->orderBy('sort_order')
                ->get(),
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

        return Inertia::render('Plan/Client', [
            // Mismo criterio que el catálogo de conductor: nunca se manda un
            // plan de nivel inferior al vigente (sección 19).
            'plans' => SubscriptionPlan::query()
                ->where('owner_type', 'client')
                ->where(fn ($query) => $query->where('is_active', true)->orWhere('code', $limits['plan_code']))
                ->where('sort_order', '>=', $limits['plan_sort_order'])
                ->orderBy('sort_order')
                ->get(),
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
}
