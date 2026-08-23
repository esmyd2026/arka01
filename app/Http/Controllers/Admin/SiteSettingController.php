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
        $request->validate([
            // 8MB: una foto de buena calidad para un fondo a pantalla
            // completa pesa bastante más que un avatar chico.
            'hero_background' => ['nullable', 'image', 'max:8192'],
            // Pedido explícito del usuario ("quita esto")... y de vuelta a
            // ninguna imagen si un admin quiere volver al fondo liso de
            // siempre, sin tener que subir nada.
            'remove_hero_background' => ['sometimes', 'boolean'],
            // Fondo del panel de marca en login/registro (pedido explícito
            // del usuario) — mismo tamaño máximo, mismo criterio.
            'auth_background' => ['nullable', 'image', 'max:8192'],
            'remove_auth_background' => ['sometimes', 'boolean'],
        ]);

        $setting = SiteSetting::current();

        $update = [
            ...$this->handleImageField($request, $setting, 'hero_background'),
            ...$this->handleImageField($request, $setting, 'auth_background'),
        ];

        $setting->update($update + ['updated_by' => $request->user()->id]);

        return back()->with('status', 'Configuración del sitio actualizada.');
    }

    /**
     * Sube/reemplaza/borra un campo de imagen del disco 'public', borrando
     * siempre el archivo anterior primero — mismo patrón para
     * hero_background y auth_background, sin duplicarlo por cada uno.
     *
     * @return array<string, string|null> vacío si no hubo cambio para ese campo
     */
    private function handleImageField(Request $request, SiteSetting $setting, string $field): array
    {
        $pathAttribute = "{$field}_path";

        if ($request->hasFile($field)) {
            if ($setting->{$pathAttribute}) {
                Storage::disk('public')->delete($setting->{$pathAttribute});
            }

            return [$pathAttribute => $request->file($field)->store('site', 'public')];
        }

        if ($request->boolean("remove_{$field}")) {
            if ($setting->{$pathAttribute}) {
                Storage::disk('public')->delete($setting->{$pathAttribute});
            }

            return [$pathAttribute => null];
        }

        return [];
    }
}
