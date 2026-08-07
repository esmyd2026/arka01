<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PricingSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pantalla de mantenimiento del cálculo de precio sugerido (sección 5): el
 * recargo nocturno y su horario. Antes eran constantes de config/arka.php;
 * ahora se editan acá y App\Services\PriceCalculator los lee de la base.
 */
class PricingSettingController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('Admin/Pricing', [
            'settings' => PricingSetting::current(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'night_surcharge_percent' => ['required', 'integer', 'min:0', 'max:200'],
            'night_starts_at' => ['required', 'integer', 'min:0', 'max:23'],
            'night_ends_at' => ['required', 'integer', 'min:0', 'max:23'],
            // Tarifa base mínima (pedido explícito del usuario): toda la
            // plataforma, no por conductor (eso ya existe como campo
            // opcional propio del conductor en su perfil, para tarifas MÁS
            // altas — este es el piso general que aplica a todos).
            'minimum_fare' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        PricingSetting::current()->update($validated);

        return back()->with('status', 'Tarifas actualizadas.');
    }
}
