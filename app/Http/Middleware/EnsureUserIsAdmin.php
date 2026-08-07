<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puerta de entrada al panel admin (activación de suscripciones y métricas,
 * sección 9.5-C): solo usuarios con is_admin, cualquier otro recibe 403 en
 * vez de una redirección confusa a otro lado.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isAdmin(), 403);

        return $next($request);
    }
}
