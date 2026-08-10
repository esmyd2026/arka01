<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Recorrido guiado por rol, una sola vez (pedido explícito del usuario) — ver
 * Components/OnboardingTour.vue y AuthenticatedLayout.vue, que lo dispara
 * solo la primera vez que corresponde (`!auth.user.onboarding_completed_at`).
 */
class OnboardingController extends Controller
{
    /**
     * Se cerró el tour de cualquier forma (terminarlo, "Saltar guía de uso",
     * click afuera, Escape) — no se distingue el motivo, cualquiera de esos
     * significa "no volver a mostrarlo solo". Volver a verlo a propósito
     * desde el panel de Ayuda no pasa por acá.
     */
    public function complete(Request $request): RedirectResponse
    {
        $request->user()->forceFill(['onboarding_completed_at' => now()])->save();

        return back();
    }
}
