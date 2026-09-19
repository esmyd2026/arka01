<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Profile\PublicProfileFinder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Perfil público desde la app móvil (roadmap app móvil, "full backend" —
 * paridad con la web). Reusa App\Services\Profile\PublicProfileFinder, la
 * misma lógica que PublicProfileController (web). Ruta pública, sin
 * Sanctum, igual que la web: cualquiera con el enlace puede verlo, incluso
 * sin cuenta.
 */
class PublicProfileController extends Controller
{
    public function __construct(private readonly PublicProfileFinder $profileFinder) {}

    public function show(Request $request, User $user): JsonResponse
    {
        // Ruta pública (sin middleware auth:sanctum) — $request->user()
        // usaría el guard 'web' por defecto y nunca vería el Bearer token.
        // Se resuelve el guard 'sanctum' a mano para saber si quien mira
        // está logueado (afecta la privacidad del perfil) sin exigirlo.
        $viewer = auth('sanctum')->user();

        return response()->json($this->profileFinder->forUser($user, $viewer));
    }
}
