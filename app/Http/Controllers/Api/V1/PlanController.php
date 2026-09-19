<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Plan\PlanUsageCalculator;
use App\Services\PlanLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Mi plan" (solo lectura) desde la app móvil (roadmap app móvil, "full
 * backend"). Reusa exactamente los mismos servicios que la web
 * (App\Services\PlanLimits, App\Services\Plan\PlanUsageCalculator,
 * extraídos de MyPlanController) — mismos límites, mismo cupo usado.
 *
 * Alcance deliberado: solo el plan vigente + cupo usado + historial de
 * cambios ya efectivos. NO incluye el catálogo de planes ni "pedir un
 * cambio de plan" (comprobante de pago, revisión de un admin, promociones/
 * cupones) — ese flujo sigue siendo solo de la web, es un feature aparte
 * mucho más grande que una lectura de estado.
 */
class PlanController extends Controller
{
    public function __construct(
        private readonly PlanLimits $planLimits,
        private readonly PlanUsageCalculator $planUsage,
    ) {}

    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isCooperative()) {
            return response()->json([
                'owner_type' => 'cooperative',
                'current_plan' => $this->planLimits->forCooperative($user),
                'used_units' => $this->planUsage->usedUnitsForCooperative($user),
                'changes' => $this->formatChanges($this->planUsage->changesFor($user, 'cooperative')),
            ]);
        }

        if ($user->isDriver()) {
            return response()->json([
                'owner_type' => 'driver',
                'current_plan' => $this->planLimits->forDriver($user),
                'used_clients' => $this->planUsage->activeClientCountForDriver($user),
                'changes' => $this->formatChanges($this->planUsage->changesFor($user, 'driver')),
            ]);
        }

        return response()->json([
            'owner_type' => 'client',
            'current_plan' => $this->planLimits->forClient($user),
            'used_fleets' => $this->planUsage->usedFleetsForClient($user),
            'max_drivers_in_any_fleet' => $this->planUsage->maxDriversInAnyFleetForClient($user),
            'changes' => $this->formatChanges($this->planUsage->changesFor($user, 'client')),
        ]);
    }

    private function formatChanges($changes): array
    {
        return $changes->map(fn ($change) => [
            'id' => $change->id,
            'old_plan' => $change->oldPlan ? ['code' => $change->oldPlan->code, 'name' => $change->oldPlan->name] : null,
            'new_plan' => ['code' => $change->newPlan->code, 'name' => $change->newPlan->name],
            'changed_at' => $change->created_at->toIso8601String(),
        ])->values()->all();
    }
}
