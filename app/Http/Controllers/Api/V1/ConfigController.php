<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
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
        ]);
    }
}
