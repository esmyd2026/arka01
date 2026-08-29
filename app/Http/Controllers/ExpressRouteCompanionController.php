<?php

namespace App\Http\Controllers;

use App\Models\ExpressRoute;
use App\Models\ExpressRouteCompanion;
use App\Services\Express\ExpressRouteCompanionResponder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Compartir un Expreso con otros clientes de ruta parecida (pedido explícito
 * del usuario): hoy un conductor no le conviene hacer un Expreso porque va
 * vacío a buscar a una sola persona por una sola carrera — si el dueño se
 * abre a que se sumen otros, el costo se reparte y el viaje sí le conviene.
 *
 * Lógica real en App\Services\Express\ExpressRouteCompanionResponder
 * (roadmap app móvil, "full backend").
 */
class ExpressRouteCompanionController extends Controller
{
    public function __construct(private readonly ExpressRouteCompanionResponder $companionResponder) {}

    /**
     * El cliente busca Expresos abiertos a compartir cuyo origen y destino
     * queden cerca de los suyos — no hace falta pertenecer a la misma flota
     * que el dueño: la gracia es conectar gente que hoy no se conoce entre sí
     * pero hace la misma ruta.
     */
    public function discover(Request $request): Response
    {
        $routes = $this->companionResponder->discover(
            $request->user(),
            $request->float('origin_lat') ?: null,
            $request->float('origin_lng') ?: null,
            $request->float('destination_lat') ?: null,
            $request->float('destination_lng') ?: null,
        );

        return Inertia::render('Express/Discover', [
            'routes' => $routes,
        ]);
    }

    /**
     * El cliente pide sumarse a un Expreso ajeno.
     */
    public function store(Request $request, ExpressRoute $route): RedirectResponse
    {
        $validated = $request->validate([
            'origin_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'origin_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'origin_address' => ['nullable', 'string', 'max:255'],
            'destination_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'destination_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'destination_address' => ['nullable', 'string', 'max:255'],
        ]);

        $this->companionResponder->request($route, $request->user(), $validated);

        return back()->with('status', 'Pedido enviado. Te avisamos cuando el dueño del Expreso responda.');
    }

    /**
     * El dueño del Expreso acepta a un acompañante.
     */
    public function accept(Request $request, ExpressRouteCompanion $companion): RedirectResponse
    {
        $this->authorize('update', $companion->route);

        $this->companionResponder->accept($companion);

        return back()->with('status', $companion->route->assigned_driver_user_id
            ? 'Aprobado por usted. Falta la confirmación del conductor.'
            : 'Acompañante aceptado. El conductor lo confirmará cuando se asigne.');
    }

    public function reject(Request $request, ExpressRouteCompanion $companion): RedirectResponse
    {
        $this->authorize('update', $companion->route);

        $this->companionResponder->reject($companion);

        return back()->with('status', 'Pedido rechazado.');
    }

    /**
     * El propio acompañante se baja, ya sea que estuviera pendiente o
     * aceptado — libera cupo para otro.
     */
    public function leave(Request $request, ExpressRouteCompanion $companion): RedirectResponse
    {
        $this->companionResponder->leave($companion, $request->user());

        return back()->with('status', 'Te bajaste del Expreso compartido.');
    }

    /** El conductor asignado confirma que puede realizar el desvío/cupo. */
    public function driverAccept(Request $request, ExpressRouteCompanion $companion): RedirectResponse
    {
        $this->companionResponder->driverAccept($companion, $request->user());

        return back()->with('status', 'Acompañante confirmado para este Expreso.');
    }

    public function driverReject(Request $request, ExpressRouteCompanion $companion): RedirectResponse
    {
        $this->companionResponder->driverReject($companion, $request->user());

        return back()->with('status', 'Acompañante rechazado; no se incluirá en las carreras.');
    }
}
