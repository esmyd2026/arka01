<?php

namespace App\Http\Controllers;

use App\Services\GoogleRoutesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MapRouteController extends Controller
{
    public function __invoke(Request $request, GoogleRoutesService $routes): JsonResponse
    {
        $validated = $request->validate([
            'origin_lat' => ['required', 'numeric', 'between:-90,90'],
            'origin_lng' => ['required', 'numeric', 'between:-180,180'],
            'destination_lat' => ['required', 'numeric', 'between:-90,90'],
            'destination_lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $route = $routes->route(
            (float) $validated['origin_lat'],
            (float) $validated['origin_lng'],
            (float) $validated['destination_lat'],
            (float) $validated['destination_lng'],
        );

        return $route
            ? response()->json($route)
            : response()->json(['message' => 'Google Routes no está disponible.'], 503);
    }
}
