<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puerta de entrada análoga a `verified` (email) pero para el teléfono,
 * verificado por WhatsApp (consideración de seguridad agregada al alcance).
 * Si el usuario no tiene teléfono cargado (ej. se registró solo con Google),
 * no se le exige nada — no hay forma de verificar un teléfono que no dio.
 */
class EnsurePhoneIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->phone && ! $user->phone_verified_at) {
            return redirect()->route('phone.verify.show');
        }

        return $next($request);
    }
}
