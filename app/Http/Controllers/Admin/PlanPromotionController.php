<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlanPromotion;
use App\Models\SubscriptionPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Promociones de precio por tiempo limitado en los planes (pedido explícito
 * del usuario: "regalar o promocionar los planes por un tiempo
 * determinado... y modificarlo desde ahí"). Mismo esqueleto que
 * Admin\AdBannerController (index/store/update/destroy, validación en un
 * método privado), sin imagen.
 */
class PlanPromotionController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/PlanPromotions', [
            'promotions' => PlanPromotion::query()->with('plan')->latest()->get(),
            'plans' => SubscriptionPlan::query()->orderBy('owner_type')->orderBy('sort_order')->get(['id', 'owner_type', 'name', 'monthly_price']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePromotion($request);

        PlanPromotion::query()->create($validated);

        return back()->with('status', 'Promoción creada.');
    }

    public function update(Request $request, PlanPromotion $planPromotion): RedirectResponse
    {
        $validated = $this->validatePromotion($request, $planPromotion);

        $planPromotion->update($validated);

        return back()->with('status', 'Promoción actualizada.');
    }

    public function destroy(PlanPromotion $planPromotion): RedirectResponse
    {
        $planPromotion->delete();

        return back()->with('status', 'Promoción eliminada.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePromotion(Request $request, ?PlanPromotion $current = null): array
    {
        $validated = $request->validate([
            'subscription_plan_id' => ['required', 'integer', 'exists:subscription_plans,id'],
            'label' => ['required', 'string', 'max:100'],
            'promo_price' => ['required', 'numeric', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['boolean'],
        ]);

        // Pedido explícito del usuario ("pagá tanto y ahorrá tanto"): si el
        // precio promocional no es menor al de lista, no hay ningún ahorro
        // que mostrar — no tiene sentido como promoción.
        $plan = SubscriptionPlan::query()->findOrFail($validated['subscription_plan_id']);

        if ($validated['promo_price'] >= $plan->monthly_price) {
            throw ValidationException::withMessages([
                'promo_price' => 'El precio promocional tiene que ser menor al precio de lista del plan ($'.number_format($plan->monthly_price, 2).').',
            ]);
        }

        $validated['is_active'] ??= true;

        return $validated;
    }
}
