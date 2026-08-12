<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     *
     * Bug real reportado por el usuario: quien entra por Google tiene una
     * contraseña al azar que nadie conoce (ver GoogleAuthController) — este
     * formulario le exigía esa contraseña actual para poder cambiarla, algo
     * que jamás iba a poder escribir bien, dejándolo bloqueado para siempre.
     * `password_set_at` distingue "ya tiene una propia" (le sigue pidiendo
     * la actual, como corresponde) de "todavía tiene la del azar de Google"
     * (la crea de cero, sin pedirle nada que no puede saber).
     */
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $hasOwnPassword = $user->password_set_at !== null;

        $validated = $request->validate([
            'current_password' => $hasOwnPassword ? ['required', 'current_password'] : ['sometimes'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'password_set_at' => now(),
        ])->save();

        return back();
    }
}
