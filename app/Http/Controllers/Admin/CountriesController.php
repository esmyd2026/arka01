<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\PricingSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pantalla de mantenimiento del catálogo de países (pedido explícito del
 * usuario: "arka01 debe funcionar en cualquier país"). De acá sale la
 * moneda, el prefijo telefónico y la región de geocodificación que usa el
 * resto del sistema — nada de eso queda quemado en código. Mismo criterio
 * que Admin\LocationController para las "zonas del Ecuador".
 */
class CountriesController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Countries', [
            'countries' => Country::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);

        $country = Country::query()->create($validated + ['is_active' => true]);

        // Un país nuevo nunca debe quedar sin fila de tarifas — si el admin
        // entra a /admin/tarifas/{pais} antes de configurar nada, encuentra
        // valores por defecto editables en vez de una pantalla en blanco o
        // un error. Los valores son los mismos defaults con los que arrancó
        // pricing_settings para Ecuador (ver create_pricing_settings_table).
        PricingSetting::query()->create([
            'country_id' => $country->id,
            'night_surcharge_percent' => 20,
            'night_starts_at' => 20,
            'night_ends_at' => 6,
            'peak_surcharge_percent' => 0,
            'peak_morning_starts_at' => 7,
            'peak_morning_ends_at' => 9,
            'peak_evening_starts_at' => 17,
            'peak_evening_ends_at' => 19,
            'pickup_surcharge_threshold_km' => 2,
            'pickup_surcharge_percent' => 0,
            'minimum_fare' => 0,
            'average_ticket_price' => 0,
        ]);

        return back()->with('status', 'País creado.');
    }

    public function update(Request $request, Country $country): RedirectResponse
    {
        $validated = $this->validated($request, $country);

        $validated['is_active'] = $request->boolean('is_active', $country->is_active);

        // Si este país pasa a ser el default, el que tenía la marca antes
        // la pierde — solo puede haber uno (ver Country::default()).
        if ($request->boolean('is_default') && ! $country->is_default) {
            Country::query()->where('is_default', true)->update(['is_default' => false]);
            $validated['is_default'] = true;
        } else {
            $validated['is_default'] = $country->is_default;
        }

        $country->update($validated);

        return back()->with('status', 'País actualizado.');
    }

    /**
     * No se borra un país que todavía tiene usuarios o tarifas propias —
     * mismo criterio de "vaciar primero" que City::destroy() en
     * LocationController, para no arrastrar historial de negocio en
     * cascada sin que el admin lo note.
     */
    public function destroy(Country $country): RedirectResponse
    {
        if ($country->is_default) {
            throw ValidationException::withMessages([
                'country' => 'Este país es el predeterminado del sistema — asigná otro como predeterminado antes de borrarlo.',
            ]);
        }

        if (PricingSetting::query()->where('country_id', $country->id)->exists()) {
            throw ValidationException::withMessages([
                'country' => 'Este país todavía tiene tarifas configuradas. Eliminalas primero.',
            ]);
        }

        $country->delete();

        return back()->with('status', 'País eliminado.');
    }

    private function validated(Request $request, ?Country $country = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'iso_code' => ['required', 'string', 'size:2', Rule::unique('countries', 'iso_code')->ignore($country?->id)],
            'phone_prefix' => ['required', 'string', 'max:6', 'regex:/^\+\d{1,5}$/', Rule::unique('countries', 'phone_prefix')->ignore($country?->id)],
            'currency_code' => ['required', 'string', 'size:3'],
            'currency_symbol' => ['required', 'string', 'max:5'],
            'decimal_digits' => ['required', 'integer', 'min:0', 'max:4'],
            'phone_local_regex' => ['nullable', 'string', 'max:255'],
            'phone_format_hint' => ['nullable', 'string', 'max:255'],
            'strips_leading_zero' => ['boolean'],
            'geocoding_region_code' => ['required', 'string', 'size:2'],
        ]);
    }
}
