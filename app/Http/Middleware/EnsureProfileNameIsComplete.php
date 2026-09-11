<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puerta de entrada análoga a `phone_verified`, pero para el nombre: el
 * registro rápido por teléfono (App\Http\Controllers\Auth\QuickRegistrationController)
 * crea la cuenta antes de pedir nombre/apellido, así que hace falta un paso
 * más para completarlo antes de dejar pasar a cualquier otra pantalla.
 */
class EnsureProfileNameIsComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->profile_name_completed_at) {
            return redirect()->route('complete-profile.show');
        }

        return $next($request);
    }
}
