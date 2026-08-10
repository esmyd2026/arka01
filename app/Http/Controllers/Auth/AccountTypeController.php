<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pedido explícito del usuario: el registro con Google (o el login con
 * Google cuando la cuenta todavía no existe) creaba la cuenta directo como
 * "cliente" sin preguntar nada — a diferencia del registro normal, que pide
 * el tipo de cuenta como primer paso (RegisteredUserController::store(),
 * Register.vue). GoogleAuthController redirige acá SOLO cuando la cuenta es
 * nueva de verdad (no cuando ya existía y solo se está logueando).
 *
 * No hace falta un POST propio: "Soy conductor" lleva a
 * driver.profile.edit (el mismo destino que ya usa el registro normal —
 * completar el perfil es lo que de verdad activa el lado conductor, ver
 * User::isDriver()) y "Soy cliente" a Inicio — ninguno de los dos necesita
 * persistir nada más en `users`, el rol real siempre se deriva de si existe
 * un DriverProfile.
 */
class AccountTypeController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Auth/ChooseAccountType');
    }
}
