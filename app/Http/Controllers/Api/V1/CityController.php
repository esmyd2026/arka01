<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\City;
use Illuminate\Http\JsonResponse;

/**
 * Catálogo de ciudades activas para selects (perfil, registro, etc.) desde
 * la app móvil (roadmap Hito 5B, paridad con la web) — mismo criterio que
 * usan CooperativeDirectoryController/VanTripController (web): sin ninguna
 * regla de negocio real, no ameritó extraer un servicio.
 *
 * Los sectores activos de cada ciudad van siempre incluidos: los usan tanto
 * el filtro del directorio como el selector de "zona de trabajo" del
 * conductor (mismo catálogo que Driver/Profile.vue y Directory/Index.vue en
 * la web — DriverProfileController::edit()/DriverDirectoryController::index()).
 */
class CityController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'cities' => City::query()
                ->where('is_active', true)
                ->with(['sectors' => fn ($query) => $query->where('is_active', true)->orderBy('name')])
                ->orderBy('name')
                ->get(),
        ]);
    }
}
