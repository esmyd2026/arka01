<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PricingSetting;
use App\Models\SubscriptionPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pantalla de mantenimiento del catálogo de planes (secciones 7.2 y 7.3): un
 * admin puede editar precios y cupos, crear planes nuevos, o desactivar uno
 * (deja de ofrecerse para altas nuevas, pero quien ya lo tiene lo conserva)
 * sin que nada de esto requiera tocar código ni volver a desplegar.
 */
class PlanController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Plans', [
            'plans' => SubscriptionPlan::query()
                ->withCount('subscriptions')
                ->orderBy('owner_type')
                ->orderBy('sort_order')
                ->get(),
            // Para que el admin vea, junto al cupo de carreras estimadas de
            // cada plan, a cuánto se traduce eso en dólares (mismo cálculo
            // que ve el conductor en "Mi plan").
            'averageTicketPrice' => (float) PricingSetting::current()->average_ticket_price,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePlan($request);

        SubscriptionPlan::query()->create($validated);

        return back()->with('status', 'Plan creado.');
    }

    public function update(Request $request, SubscriptionPlan $plan): RedirectResponse
    {
        $validated = $this->validatePlan($request, $plan);

        $plan->update($validated);

        return back()->with('status', 'Plan actualizado.');
    }

    /**
     * El plan Gratis de cada lado es la base del sistema (PlanLimits cae ahí
     * si un usuario no tiene ninguna suscripción vigente): nunca se puede
     * borrar. Tampoco se borra un plan que ya tiene suscripciones — activas
     * o históricas — porque la relación está en cascadeOnDelete() y borrarlo
     * se llevaría puesto ese historial sin que nadie lo haya pedido.
     */
    public function destroy(SubscriptionPlan $plan): RedirectResponse
    {
        if ($plan->code === 'gratis') {
            throw ValidationException::withMessages([
                'plan' => 'El plan Gratis no se puede eliminar: es la base a la que cae cualquiera sin suscripción.',
            ]);
        }

        if ($plan->subscriptions()->exists()) {
            throw ValidationException::withMessages([
                'plan' => 'Este plan ya tiene suscripciones (activas o históricas). Desactivalo en vez de borrarlo.',
            ]);
        }

        $plan->delete();

        return back()->with('status', 'Plan eliminado.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePlan(Request $request, ?SubscriptionPlan $plan = null): array
    {
        $validated = $request->validate([
            'owner_type' => ['required', Rule::in(['driver', 'client', 'cooperative'])],
            'code' => [
                'required', 'string', 'max:50', 'alpha_dash',
                Rule::unique('subscription_plans', 'code')
                    ->where('owner_type', $request->input('owner_type'))
                    ->ignore($plan?->id),
            ],
            'name' => ['required', 'string', 'max:100'],
            'monthly_price' => ['required', 'numeric', 'min:0'],
            // Descuento que este plan de COOPERATIVA le da al conductor
            // afiliado en su propio plan individual (pedido explícito del
            // usuario) — sin efecto en planes de conductor/cliente.
            'driver_discount_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'max_clients' => ['nullable', 'integer', 'min:0'],
            'estimated_monthly_rides' => ['nullable', 'integer', 'min:0'],
            'public_visibility' => ['boolean'],
            'priority_listing' => ['boolean'],
            'verified_badge' => ['boolean'],
            'van_trips_enabled' => ['boolean'],
            'express_enabled' => ['boolean'],
            // Pedido explícito del usuario: habilita que los conductores de
            // este plan acepten solicitudes de MÁS de una cooperativa a la
            // vez (por defecto solo pueden estar afiliados a una) — ver
            // CooperativeDriverResponder::respond().
            'multi_cooperative_enabled' => ['boolean'],
            'max_fleets' => ['nullable', 'integer', 'min:0'],
            'max_drivers_per_fleet' => ['nullable', 'integer', 'min:0'],
            'max_cooperatives' => ['nullable', 'integer', 'min:0'],
            'max_units' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        $validated['driver_discount_percent'] ??= 0;
        $validated['public_visibility'] ??= false;
        $validated['priority_listing'] ??= false;
        $validated['verified_badge'] ??= false;
        $validated['van_trips_enabled'] ??= false;
        $validated['multi_cooperative_enabled'] ??= false;
        // A diferencia de las demás (premium, arrancan apagadas), Expresos ya
        // era una función abierta antes de este flag — un plan nuevo sin
        // especificarlo explícitamente arranca CON el módulo habilitado.
        $validated['express_enabled'] ??= true;
        $validated['is_active'] ??= true;
        $validated['sort_order'] ??= 0;

        return $validated;
    }
}
