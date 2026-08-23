<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administración → Sitio (pedido explícito del usuario: "por lo menos haz
 * que la pueda colocar desde la parte de configuración del admin" — la
 * imagen de fondo del hero de Welcome.vue). Fila única, mismo patrón que
 * Admin\PricingSettingController/WhatsAppSettingController.
 */
class SiteSettingController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('Admin/Site/Edit', [
            'settings' => SiteSetting::current(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            // 8MB: una foto de buena calidad para un fondo a pantalla
            // completa pesa bastante más que un avatar chico.
            'hero_background' => ['nullable', 'image', 'max:8192'],
            // Pedido explícito del usuario ("quita esto")... y de vuelta a
            // ninguna imagen si un admin quiere volver al fondo liso de
            // siempre, sin tener que subir nada.
            'remove_hero_background' => ['sometimes', 'boolean'],
        ]);

        $setting = SiteSetting::current();

        if ($request->hasFile('hero_background')) {
            if ($setting->hero_background_path) {
                Storage::disk('public')->delete($setting->hero_background_path);
            }
            $validated['hero_background_path'] = $request->file('hero_background')->store('site', 'public');
        } elseif ($request->boolean('remove_hero_background')) {
            if ($setting->hero_background_path) {
                Storage::disk('public')->delete($setting->hero_background_path);
            }
            $validated['hero_background_path'] = null;
        }

        unset($validated['hero_background'], $validated['remove_hero_background']);

        $setting->update($validated + ['updated_by' => $request->user()->id]);

        return back()->with('status', 'Configuración del sitio actualizada.');
    }
}
