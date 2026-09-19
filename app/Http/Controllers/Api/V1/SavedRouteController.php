<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SavedRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Mis rutas" desde la app móvil (roadmap app móvil, "full backend" —
 * paridad con la web). Sin servicio aparte: es un CRUD simple sin ninguna
 * regla de negocio real de por medio, mismas validaciones que
 * SavedRouteController (web).
 */
class SavedRouteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['saved_routes' => $request->user()->savedRoutes()->latest()->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'alias' => ['nullable', 'string', 'max:50'],
            'origin_lat' => ['required', 'numeric', 'between:-90,90'],
            'origin_lng' => ['required', 'numeric', 'between:-180,180'],
            'origin_address' => ['nullable', 'string', 'max:255'],
            'origin_sector_id' => ['nullable', 'integer', 'exists:sectors,id'],
            'destination_lat' => ['required', 'numeric', 'between:-90,90'],
            'destination_lng' => ['required', 'numeric', 'between:-180,180'],
            'destination_address' => ['nullable', 'string', 'max:255'],
            'destination_sector_id' => ['nullable', 'integer', 'exists:sectors,id'],
        ]);

        $savedRoute = $request->user()->savedRoutes()->create($validated);

        return response()->json(['saved_route' => $savedRoute], 201);
    }

    public function destroy(Request $request, SavedRoute $savedRoute): JsonResponse
    {
        abort_unless($savedRoute->client_user_id === $request->user()->id, 403);

        $savedRoute->delete();

        return response()->json(['message' => 'Ruta eliminada.']);
    }
}
