<?php

namespace App\Services\Express;

use App\Models\ExpressRoute;
use App\Models\ExpressRouteCompanion;
use App\Models\User;
use App\Notifications\ExpressCompanionApprovalPushNotification;
use App\Services\Haversine;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Compartir un Expreso con otros clientes de ruta parecida — extraído de
 * ExpressRouteCompanionController (roadmap app móvil, "full backend": nunca
 * duplicar una regla de negocio entre web y móvil).
 */
class ExpressRouteCompanionResponder
{
    /**
     * Radio de cercanía (km) para considerar que dos rutas "van por el mismo
     * lado" — ni tan chico que no encuentre nada, ni tan grande que sugiera
     * compartir con alguien que en la práctica queda lejos del recorrido.
     */
    public const MATCH_RADIUS_KM = 2.5;

    /**
     * El cliente busca Expresos abiertos a compartir cuyo origen y destino
     * queden cerca de los suyos — no hace falta pertenecer a la misma flota
     * que el dueño.
     */
    public function discover(User $client, ?float $originLat, ?float $originLng, ?float $destinationLat, ?float $destinationLng): Collection
    {
        if ($originLat === null || $destinationLat === null) {
            return collect();
        }

        $alreadyRequestedRouteIds = ExpressRouteCompanion::query()
            ->where('passenger_user_id', $client->id)
            ->whereIn('status', ['pending', 'accepted'])
            ->pluck('express_route_id');

        return ExpressRoute::query()
            ->where('share_enabled', true)
            ->whereIn('status', ['open', 'active'])
            ->where('client_user_id', '!=', $client->id)
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

    /**
     * El cliente pide sumarse a un Expreso ajeno.
     */
    public function request(ExpressRoute $route, User $client, array $validated): ExpressRouteCompanion
    {
        if ($route->client_user_id === $client->id) {
            abort(403);
        }

        if (! $route->hasRoomForCompanions()) {
            throw ValidationException::withMessages([
                'route' => 'Este Expreso ya no está abierto a compartir (sin cupo, o el dueño lo desactivó).',
            ]);
        }

        $alreadyRequested = ExpressRouteCompanion::query()
            ->where('express_route_id', $route->id)
            ->where('passenger_user_id', $client->id)
            ->whereIn('status', ['pending', 'accepted'])
            ->exists();

        if ($alreadyRequested) {
            throw ValidationException::withMessages([
                'route' => 'Ya pediste sumarte a este Expreso.',
            ]);
        }

        return ExpressRouteCompanion::query()->create([
            'express_route_id' => $route->id,
            'passenger_user_id' => $client->id,
            ...$validated,
            'status' => 'pending',
            'requested_at' => now(),
        ]);
    }

    /**
     * El dueño del Expreso acepta a un acompañante — no cambia el precio
     * pactado con el conductor, solo reparte el costo entre más gente.
     */
    public function accept(ExpressRouteCompanion $companion): void
    {
        $route = $companion->route;

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

        $companion->update([
            'status' => 'accepted',
            'responded_at' => now(),
            'driver_approval_status' => $route->assigned_driver_user_id ? 'pending' : null,
        ]);

        if ($route->assignedDriver) {
            $route->assignedDriver->notify(new ExpressCompanionApprovalPushNotification($companion, 'review'));
        }
    }

    public function reject(ExpressRouteCompanion $companion): void
    {
        if ($companion->status !== 'pending') {
            throw ValidationException::withMessages([
                'companion' => 'Este pedido ya no está pendiente.',
            ]);
        }

        $companion->update(['status' => 'rejected', 'responded_at' => now()]);
    }

    /**
     * El propio acompañante se baja, ya sea que estuviera pendiente o
     * aceptado — libera cupo para otro.
     */
    public function leave(ExpressRouteCompanion $companion, User $actingUser): void
    {
        if ($companion->passenger_user_id !== $actingUser->id) {
            abort(403);
        }

        $companion->update(['status' => 'left', 'responded_at' => now()]);
    }

    /** El conductor asignado confirma que puede realizar el desvío/cupo. */
    public function driverAccept(ExpressRouteCompanion $companion, User $actingUser): void
    {
        $this->authorizeDriverDecision($companion, $actingUser);

        $companion->update([
            'driver_approval_status' => 'accepted',
            'driver_responded_at' => now(),
        ]);

        $companion->passenger->notify(new ExpressCompanionApprovalPushNotification($companion, 'accepted'));
    }

    public function driverReject(ExpressRouteCompanion $companion, User $actingUser): void
    {
        $this->authorizeDriverDecision($companion, $actingUser);

        $companion->update([
            'status' => 'rejected',
            'driver_approval_status' => 'rejected',
            'driver_responded_at' => now(),
        ]);

        $companion->passenger->notify(new ExpressCompanionApprovalPushNotification($companion, 'rejected'));
    }

    private function authorizeDriverDecision(ExpressRouteCompanion $companion, User $actingUser): void
    {
        abort_unless($companion->route->assigned_driver_user_id === $actingUser->id, 403);

        if ($companion->status !== 'accepted' || $companion->driver_approval_status !== 'pending') {
            throw ValidationException::withMessages([
                'companion' => 'Este acompañante ya no está esperando su decisión.',
            ]);
        }
    }
}
