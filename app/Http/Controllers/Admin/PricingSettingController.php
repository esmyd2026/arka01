<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\PricingSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pantalla de mantenimiento del cálculo de precio sugerido (sección 5): el
 * recargo nocturno y su horario. Antes eran constantes de config/arka.php;
 * después pasaron a una única fila global en la base — bug reportado por el
 * usuario: un tope de tarifa mínima en dólares le rompía el registro a un
 * conductor que cobra en pesos chilenos. Ahora hay UNA fila por país (ver
 * App\Models\PricingSetting::forCountry()) — index() lista los países,
 * edit()/update() trabajan sobre la fila de uno puntual.
 */
class PricingSettingController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Pricing/Index', [
            'countries' => Country::active()->values(),
        ]);
    }

    public function edit(Country $country): Response
    {
        return Inertia::render('Admin/Pricing/Edit', [
            'country' => $country,
            'settings' => PricingSetting::forCountry($country),
        ]);
    }

    public function update(Request $request, Country $country): RedirectResponse
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
            // plataforma DE ESE PAÍS, no por conductor (eso ya existe como
            // campo opcional propio del conductor en su perfil, para
            // tarifas MÁS altas — este es el piso general que aplica a
            // todos los conductores de este país). Sin tope fijo en código:
            // cada país cobra en su propia moneda y escala (3 para Ecuador,
            // 3000 para Chile son ambos razonables).
            'minimum_fare' => ['required', 'numeric', 'min:0'],
            // Ticket promedio por carrera (pedido explícito del usuario):
            // alimenta la proyección de ganancia mensual del catálogo de
            // planes de conductor (ver SubscriptionPlan).
            'average_ticket_price' => ['required', 'numeric', 'min:0'],
        ]);

        PricingSetting::forCountry($country)->update($validated);

        return back()->with('status', 'Tarifas actualizadas.');
    }
}
