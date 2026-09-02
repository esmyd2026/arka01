<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pedido explícito del usuario: "la cooperativas deberian ser como los
 * conductores si no me llenan todo no pueden ir a mas ningun lado hasta que
 * yo les verifique y les apruebe" — hoy una cooperativa recién registrada
 * (perfil vacío, status='pending') podía navegar libremente a su panel,
 * conductores, billetera, etc.; solo el perfil mostraba un aviso de que
 * "aún no puede operar", pero nada se lo impedía de verdad. Se aplica a
 * todo el grupo `cooperative` MENOS las rutas de perfil/documentos (ver
 * routes/web.php) — esas son justamente las que necesita para completar y
 * enviar su postulación, así que quedan siempre accesibles.
 */
class EnsureCooperativeIsApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $cooperative = $request->user()?->cooperative;

        if ($cooperative && $cooperative->status !== 'approved') {
            return redirect()->route('cooperative.profile.edit')
                ->with('status', 'Complete y envíe la documentación de la cooperativa — un administrador debe revisarla y aprobarla antes de que pueda operar.');
        }

        return $next($request);
    }
}
