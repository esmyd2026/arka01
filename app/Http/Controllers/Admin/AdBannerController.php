<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdBanner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mantenimiento del módulo de publicidad (pedido explícito del usuario):
 * crear, editar, eliminar, activar/desactivar banners, orden de aparición y
 * fechas de vigencia — todo administrable, nada quemado en código.
 */
class AdBannerController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/AdBanners', [
            'banners' => AdBanner::query()->orderBy('sort_order')->orderByDesc('created_at')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateBanner($request);

        $validated['image_path'] = $request->file('image')->store('ad-banners', 'public');

        AdBanner::query()->create($validated);

        return back()->with('status', 'Banner creado.');
    }

    public function update(Request $request, AdBanner $adBanner): RedirectResponse
    {
        $validated = $this->validateBanner($request, isUpdate: true);

        if ($request->hasFile('image')) {
            Storage::disk('public')->delete($adBanner->image_path);
            $validated['image_path'] = $request->file('image')->store('ad-banners', 'public');
        }

        $adBanner->update($validated);

        return back()->with('status', 'Banner actualizado.');
    }

    public function destroy(AdBanner $adBanner): RedirectResponse
    {
        Storage::disk('public')->delete($adBanner->image_path);
        $adBanner->delete();

        return back()->with('status', 'Banner eliminado.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateBanner(Request $request, bool $isUpdate = false): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'button_label' => ['nullable', 'string', 'max:50'],
            'button_url' => ['nullable', 'url', 'max:255'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            // Al crear hace falta la imagen; al editar es opcional (se
            // conserva la actual si no se manda una nueva).
            'image' => [$isUpdate ? 'nullable' : 'required', 'image', 'max:4096'],
        ]);

        unset($validated['image']);
        $validated['is_active'] ??= true;
        $validated['sort_order'] ??= 0;

        return $validated;
    }
}
