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

    /**
     * Tutorial guiado con Driver.js, anclado a los controles reales de pedir
     * una carrera (destino, forma de pago, agregar parada, invertir
     * origen/destino) — ver Ride/Request.vue y Utils/rideRequestTour.js.
     * Mismo criterio que complete(): cualquier forma de cerrarlo cuenta como
     * "ya lo vio", volver a verlo desde el botón de Ayuda no pasa por acá.
     */
    public function completeRideRequestTour(Request $request): RedirectResponse
    {
        $request->user()->forceFill(['ride_request_tour_seen_at' => now()])->save();

        return back();
    }

    /**
     * Tutorial guiado de "agregar conductores a mi flota" — ver
     * Components/FleetRoster.vue y Utils/fleetTour.js.
     */
    public function completeFleetTour(Request $request): RedirectResponse
    {
        $request->user()->forceFill(['fleet_tour_seen_at' => now()])->save();

        return back();
    }

    /**
     * Tutorial guiado de completar el perfil de conductor (vehículo,
     * tarifa, forma de pago, documentos) — ver Driver/Profile.vue.
     */
    public function completeDriverProfileTour(Request $request): RedirectResponse
    {
        $request->user()->forceFill(['driver_profile_tour_seen_at' => now()])->save();

        return back();
    }
}
