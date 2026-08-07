<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Sector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pantalla de mantenimiento del catálogo de "zonas del Ecuador" (ciudades y
 * sectores/barrios, consideración agregada al alcance): de acá sale la lista
 * que el cliente usa al pedir una carrera (sección 3.5) para indicar dónde
 * está y a dónde va sin abrir el mapa. Nada de esto queda quemado en código.
 */
class LocationController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Locations', [
            'cities' => City::query()
                ->withCount('sectors')
                ->with(['sectors' => fn ($query) => $query->orderBy('name')])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function storeCity(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('cities', 'name')],
            'province' => ['nullable', 'string', 'max:100'],
            // Para poder centrar el mapa ahí cuando alguien la elige al pedir
            // una carrera (consideración agregada al alcance).
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        City::query()->create($validated + ['is_active' => true]);

        return back()->with('status', 'Ciudad creada.');
    }

    public function updateCity(Request $request, City $city): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('cities', 'name')->ignore($city->id)],
            'province' => ['nullable', 'string', 'max:100'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'is_active' => ['boolean'],
        ]);

        $validated['is_active'] ??= true;

        $city->update($validated);

        return back()->with('status', 'Ciudad actualizada.');
    }

    /**
     * No se borra una ciudad que todavía tiene sectores cargados — primero
     * hay que vaciarla, para no borrar en cascada sin que el admin lo note.
     */
    public function destroyCity(City $city): RedirectResponse
    {
        if ($city->sectors()->exists()) {
            throw ValidationException::withMessages([
                'city' => 'Esta ciudad todavía tiene sectores cargados. Eliminalos primero.',
            ]);
        }

        $city->delete();

        return back()->with('status', 'Ciudad eliminada.');
    }

    public function storeSector(Request $request, City $city): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('sectors', 'name')->where('city_id', $city->id)],
        ]);

        $city->sectors()->create($validated + ['is_active' => true]);

        return back()->with('status', 'Sector creado.');
    }

    public function updateSector(Request $request, Sector $sector): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('sectors', 'name')->where('city_id', $sector->city_id)->ignore($sector->id)],
            'is_active' => ['boolean'],
        ]);

        $validated['is_active'] ??= true;

        $sector->update($validated);

        return back()->with('status', 'Sector actualizado.');
    }

    /**
     * A diferencia de la ciudad, el sector sí se puede borrar directo: las
     * referencias existentes (usuarios, solicitudes, carreras) quedan en
     * null (nullOnDelete) en vez de romperse — no arrastra historial propio.
     */
    public function destroySector(Sector $sector): RedirectResponse
    {
        $sector->delete();

        return back()->with('status', 'Sector eliminado.');
    }
}
