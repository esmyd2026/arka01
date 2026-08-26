<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Pedido explícito del usuario ("deberiamos verificar que si no tiene
 * cuenta preguntarle si quiere registrarse para luego sus solicitudes sean
 * mas rapido") — una cuenta creada sola en la primera reserva por WhatsApp
 * (ver WhatsAppRideBookingHandler) queda con una contraseña al azar que la
 * persona nunca vio, así que no puede entrar por el login normal ni pedir
 * "olvidé mi contraseña" (su correo también es un placeholder, no uno
 * real). Este link, mandado por WhatsApp, la deja entrar sin contraseña —
 * mismo patrón ya probado que Auth\SessionTakeoverController::lock(): la
 * firma de la URL (middleware `signed`) es la única verificación, nadie
 * puede armar este link a mano.
 */
class GuestAccountController extends Controller
{
    public function completeRegistration(User $user): RedirectResponse
    {
        Auth::login($user);

        return redirect()->route('profile.edit')
            ->with('status', 'Complete su correo y contraseña para terminar de registrarse.');
    }
}
