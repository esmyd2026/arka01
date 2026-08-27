<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bug reportado por el usuario: "se estan registrando como conductor y el
 * sistema termina creandole como [cliente]" — elegir "conductor" en el
 * registro solo redirige a completar el perfil (RegisteredUserController::store());
 * si esa persona abandona ese paso, la cuenta queda funcionando como
 * cliente para siempre, sin ningún aviso, porque `isDriver()` recién es
 * true cuando existe un DriverProfile de verdad. Mismo criterio y mismo
 * punto de entrada que `EnsurePhoneIsVerified` (solo /dashboard, no toda la
 * app): lo devuelve a terminar en vez de dejarlo navegar como cliente sin
 * darse cuenta de que le falta un paso.
 */
class EnsureDriverOnboardingIsComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->intends_to_drive && ! $user->isDriver()) {
            return redirect()->route('driver.profile.edit')
                ->with('status', 'Le falta completar los datos de su vehículo para activar su cuenta de conductor.');
        }

        return $next($request);
    }
}
