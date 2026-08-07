<?php

namespace App\Http\Controllers;

use App\Models\ExpressRoute;
use App\Models\ExpressRouteCompanion;
use App\Services\Haversine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Compartir un Expreso con otros clientes de ruta parecida (pedido explícito
 * del usuario): hoy un conductor no le conviene hacer un Expreso porque va
 * vacío a buscar a una sola persona por una sola carrera — si el dueño se
 * abre a que se sumen otros, el costo se reparte y el viaje sí le conviene.
 */
class ExpressRouteCompanionController extends Controller
{
    /**
     * Radio de cercanía (km) para considerar que dos rutas "van por el mismo
     * lado" — ni tan chico que no encuentre nada, ni tan grande que sugiera
     * compartir con alguien que en la práctica queda lejos del recorrido.
     */
    private const MATCH_RADIUS_KM = 2.5;

    /**
     * El cliente busca Expresos abiertos a compartir cuyo origen y destino
     * queden cerca de los suyos — no hace falta pertenecer a la misma flota
     * que el dueño: la gracia es conectar gente que hoy no se conoce entre sí
     * pero hace la misma ruta.
     */
    public function discover(Request $request): Response
    {
        $originLat = $request->float('origin_lat') ?: null;
        $originLng = $request->float('origin_lng') ?: null;
        $destinationLat = $request->float('destination_lat') ?: null;
        $destinationLng = $request->float('destination_lng') ?: null;

        $routes = collect();

        if ($originLat !== null && $destinationLat !== null) {
            $alreadyRequestedRouteIds = ExpressRouteCompanion::query()
                ->where('passenger_user_id', $request->user()->id)
                ->whereIn('status', ['pending', 'accepted'])
                ->pluck('express_route_id');

            $routes = ExpressRoute::query()
                ->where('share_enabled', true)
                ->whereIn('status', ['open', 'active'])
                ->where('client_user_id', '!=', $request->user()->id)
                ->whereNotIn('id', $alreadyRequestedRouteIds)
                ->with('client')
                ->get()
                ->map(function (ExpressRoute $route) use ($originLat, $originLng, $destinationLat, $destinationLng) {
                    $route->origin_distance_km = round(Haversine::distanceKm($originLat, $originLng, (float) $route->origin_lat, (float) $route->origin_lng), 2);
                    $route->destination_distance_km = round(Haversine::distanceKm($destinationLat, $destinationLng, (float) $route->destination_lat, (float) $route->destination_lng), 2);
                    // Precio por persona SI el que se suma llega a aceptarse (todavía
                    // no está aceptado, es la vidriera para decidir si conviene pedir).
                    $route->price_per_person = round((float) $route->offered_price / (2 + $route->acceptedCompanionsCount()), 2);

                    return $route;
                })
                ->filter(fn (ExpressRoute $route) => $route->origin_distance_km <= self::MATCH_RADIUS_KM
                    && $route->destination_distance_km <= self::MATCH_RADIUS_KM
                    && $route->hasRoomForCompanions())
                ->sortBy(fn (ExpressRoute $route) => $route->origin_distance_km + $route->destination_distance_km)
                ->values();
        }

        return Inertia::render('Express/Discover', [
            'routes' => $routes,
        ]);
    }

    /**
     * El cliente pide sumarse a un Expreso ajeno.
     */
    public function store(Request $request, ExpressRoute $route): RedirectResponse
    {
        if ($route->client_user_id === $request->user()->id) {
            abort(403);
        }

        if (! $route->hasRoomForCompanions()) {
            throw ValidationException::withMessages([
                'route' => 'Este Expreso ya no está abierto a compartir (sin cupo, o el dueño lo desactivó).',
            ]);
        }

        $alreadyRequested = ExpressRouteCompanion::query()
            ->where('express_route_id', $route->id)
            ->where('passenger_user_id', $request->user()->id)
            ->whereIn('status', ['pending', 'accepted'])
            ->exists();

        if ($alreadyRequested) {
            throw ValidationException::withMessages([
                'route' => 'Ya pediste sumarte a este Expreso.',
            ]);
        }

        $validated = $request->validate([
            'origin_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'origin_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'origin_address' => ['nullable', 'string', 'max:255'],
            'destination_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'destination_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'destination_address' => ['nullable', 'string', 'max:255'],
        ]);

        ExpressRouteCompanion::query()->create([
            'express_route_id' => $route->id,
            'passenger_user_id' => $request->user()->id,
            ...$validated,
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        return back()->with('status', 'Pedido enviado. Te avisamos cuando el dueño del Expreso responda.');
    }

    /**
     * El dueño del Expreso acepta a un acompañante — no cambia el precio
     * pactado con el conductor, solo reparte el costo entre más gente (ver
     * ExpressRoute::pricePerPerson()).
     */
    public function accept(Request $request, ExpressRouteCompanion $companion): RedirectResponse
    {
        $route = $companion->route;
        $this->authorize('update', $route);

        if ($companion->status !== 'pending') {
            throw ValidationException::withMessages([
                'companion' => 'Este pedido ya no está pendiente.',
            ]);
        }

        if (! $route->hasRoomForCompanions()) {
            throw ValidationException::withMessages([
                'companion' => 'Ya no hay cupo para más acompañantes.',
            ]);
        }

        $companion->update(['status' => 'accepted', 'responded_at' => now()]);

        return back()->with('status', 'Acompañante aceptado.');
    }

    public function reject(Request $request, ExpressRouteCompanion $companion): RedirectResponse
    {
        $this->authorize('update', $companion->route);

        if ($companion->status !== 'pending') {
            throw ValidationException::withMessages([
                'companion' => 'Este pedido ya no está pendiente.',
            ]);
        }

        $companion->update(['status' => 'rejected', 'responded_at' => now()]);

        return back()->with('status', 'Pedido rechazado.');
    }

    /**
     * El propio acompañante se baja, ya sea que estuviera pendiente o
     * aceptado — libera cupo para otro.
     */
    public function leave(Request $request, ExpressRouteCompanion $companion): RedirectResponse
    {
        if ($companion->passenger_user_id !== $request->user()->id) {
            abort(403);
        }

        $companion->update(['status' => 'left', 'responded_at' => now()]);

        return back()->with('status', 'Te bajaste del Expreso compartido.');
    }
}
