<?php

namespace App\Http\Controllers;

use App\Models\Ride;
use App\Services\Security\SosAlertSender;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Lógica real en App\Services\Security\SosAlertSender (roadmap app móvil,
 * "full backend").
 */
class SosAlertController extends Controller
{
    public function __construct(private readonly SosAlertSender $sosAlertSender) {}

    public function store(Request $request, Ride $ride): RedirectResponse
    {
        $result = $this->sosAlertSender->trigger($ride, $request->user());

        $message = $result['notified'] > 0
            ? "Alerta enviada a {$result['notified']} contacto(s) de confianza."
            : 'Se registró la alerta, pero no tiene contactos de confianza con correo cargado.';

        return back()->with('status', $message);
    }
}
