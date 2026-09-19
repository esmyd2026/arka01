<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Recorrido guiado por rol desde la app móvil (roadmap Hito 5B, paridad
 * con la web) — misma regla que OnboardingController (web): se marca
 * visto una sola vez, sin distinguir el motivo del cierre.
 */
class OnboardingController extends Controller
{
    public function complete(Request $request): JsonResponse
    {
        $request->user()->forceFill(['onboarding_completed_at' => now()])->save();

        return response()->json(['message' => 'Recorrido marcado como visto.']);
    }
}
