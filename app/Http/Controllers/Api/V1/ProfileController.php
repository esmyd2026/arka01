<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserResource;
use App\Services\Profile\ProfileUpdater;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Editar los datos personales del usuario (cliente o conductor) desde la
 * app móvil (roadmap app móvil, "full backend" — paridad con la web).
 * Reusa App\Services\Profile\ProfileUpdater, la misma lógica que
 * ProfileController (web).
 */
class ProfileController extends Controller
{
    public function __construct(private readonly ProfileUpdater $profileUpdater) {}

    public function update(Request $request): JsonResponse
    {
        $user = $this->profileUpdater->update($request);

        return response()->json(['user' => new UserResource($user->fresh())]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'current_password' => $user->password_set_at !== null ? ['required', 'current_password'] : ['sometimes'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $user->forceFill(['password' => Hash::make($validated['password']), 'password_set_at' => now()])->save();

        return response()->json(['message' => 'Contraseña actualizada.']);
    }

    /**
     * Buscar a quién marcar como "quién lo recomendó" — mismo criterio que
     * ProfileController::searchReferrer() (web): por nombre, usuario o
     * código, a diferencia del resto de buscadores de la app (solo código),
     * porque es sobre la propia cuenta del usuario, no un listado público.
     */
    public function searchReferrer(Request $request): JsonResponse
    {
        $validated = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);

        return response()->json(['users' => $this->profileUpdater->searchReferrer($request->user(), $validated['q'])]);
    }

    public function setReferrer(Request $request): JsonResponse
    {
        $validated = $request->validate(['referrer_user_id' => ['required', 'integer', 'exists:users,id']]);

        $this->profileUpdater->setReferrer($request->user(), (int) $validated['referrer_user_id']);

        return response()->json(['user' => new UserResource($request->user()->fresh())]);
    }
}
