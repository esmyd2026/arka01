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
 */
class CityController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'cities' => City::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
