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
            // Recargo de hora pico (pedido explícito del usuario: "subir un
            // poco las tarifas en las horas pico"), dos franjas — mañana y
            // tarde — mismo criterio que el nocturno de arriba.
            'peak_surcharge_percent' => ['required', 'integer', 'min:0', 'max:200'],
            'peak_morning_starts_at' => ['required', 'integer', 'min:0', 'max:23'],
            'peak_morning_ends_at' => ['required', 'integer', 'min:0', 'max:23'],
            'peak_evening_starts_at' => ['required', 'integer', 'min:0', 'max:23'],
            'peak_evening_ends_at' => ['required', 'integer', 'min:0', 'max:23'],
            // Cargo por trayecto de recogida (pedido explícito del usuario):
            // bajo el umbral se sigue usando el colchón fijo de 0.8 km que ya
            // existe (App\Services\PriceCalculator::DISTANCE_PADDING_KM), sin
            // cargo aparte. Sobre el umbral, se cobra distancia_recogida ×
            // tarifa_del_conductor × este porcentaje — ver
            // App\Services\PriceCalculator::pickupSurcharge().
            'pickup_surcharge_threshold_km' => ['required', 'numeric', 'min:0', 'max:50'],
            'pickup_surcharge_percent' => ['required', 'integer', 'min:0', 'max:200'],
            // Tarifa base mínima (pedido explícito del usuario): toda la
            // plataforma, no por conductor (eso ya existe como campo
            // opcional propio del conductor en su perfil, para tarifas MÁS
            // altas — este es el piso general que aplica a todos).
            'minimum_fare' => ['required', 'numeric', 'min:0', 'max:100'],
            // Ticket promedio por carrera (pedido explícito del usuario):
            // alimenta la proyección de ganancia mensual del catálogo de
            // planes de conductor (ver SubscriptionPlan).
            'average_ticket_price' => ['required', 'numeric', 'min:0', 'max:1000'],
            // Antes fija en código (pedido explícito del usuario: poder
            // ajustarla sin desplegar) — ver DriverProfile::staleAfterMinutes().
            // El barrido automático (drivers:sweep-stale-availability) sigue
            // corriendo cada 2 min sin importar este valor, así que bajarlo
            // por debajo de eso puede tardar hasta 2 min en aplicarse del
            // lado de ESE comando puntual (el resto de la app lo aplica al
            // instante).
            'driver_stale_after_minutes' => ['required', 'integer', 'min:1', 'max:60'],
        ]);

        PricingSetting::current()->update($validated);

        return back()->with('status', 'Tarifas actualizadas.');
    }
}
