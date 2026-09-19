<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Auth\DriverOfflineOnLogout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Eliminación de cuenta desde la app (roadmap Hito 4: "implementar
 * eliminación de cuenta accesible desde la app"). Mismo criterio que
 * ProfileController::destroy() (web): pide la contraseña actual antes de
 * borrar — acá se valida a mano con Hash::check() en vez de la regla
 * `current_password` porque esa regla mira el guard `web` por defecto, y
 * esta request está autenticada por token Sanctum, no por sesión.
 */
class AccountController extends Controller
{
    public function __construct(private readonly DriverOfflineOnLogout $driverOfflineOnLogout) {}

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'La contraseña no es correcta.',
            ]);
        }

        // Mismo efecto que cerrar sesión: si era un conductor disponible,
        // no puede seguir apareciendo así para su flota una vez borrada la
        // cuenta.
        $this->driverOfflineOnLogout->handle($user);

        // Todos los tokens de todos los dispositivos, no solo el actual —
        // la cuenta deja de existir, no tiene sentido dejar uno vivo.
        $user->tokens()->delete();

        $user->delete();

        return response()->json(['message' => 'Cuenta eliminada.']);
    }
}
