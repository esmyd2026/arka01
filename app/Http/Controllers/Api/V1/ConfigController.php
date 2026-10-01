<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Country;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint de arranque de la app móvil (roadmap Hito 2: "Configuración
 * móvil: versión mínima, mantenimiento y banderas de funciones") — sin login
 * todavía, así la app sabe si tiene que forzar una actualización o mostrar
 * mantenimiento antes de dejar entrar a nadie.
 */
class ConfigController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $platform = $request->query('platform');

        return response()->json([
            'min_version' => $platform && in_array($platform, ['android', 'ios'], true)
                ? config("mobile.min_version.{$platform}")
                : config('mobile.min_version'),
            'maintenance' => (bool) config('mobile.maintenance'),
            // Catálogo de países administrable desde /admin/paises (pedido
            // explícito del usuario: "arka01 debe funcionar en cualquier
            // país") — antes el selector de país del registro móvil traía
            // una lista de prefijos fija en el bundle de la app.
            'countries' => Country::active()->map->publicPayload()->values(),
        ]);
    }
}
