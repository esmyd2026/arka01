<?php

namespace App\Services\Ride;

use App\Models\ClientCooperative;
use App\Models\Cooperative;
use App\Models\User;
use App\Services\Haversine;
use Illuminate\Support\Collection;

/**
 * Cooperativas disponibles para "Elige tu conductor" al pedir una carrera —
 * extraído de RideRequestController::cooperativesFor() (web) para que la
 * app móvil ofrezca exactamente las mismas opciones (mismo criterio que el
 * resto de App\Services\Ride\*: nunca duplicar una regla de negocio entre
 * web y móvil).
 */
class RideRequestCooperativeOptions
{
    /**
     * Las que el cliente agregó a su red (ClientCooperative) más las que un
     * admin marcó como públicas (Cooperative.is_public). Cada una se marca
     * con is_added para que el front distinga "de mi red" de "solo pública"
     * — la de su red gana siempre al recomendar, sin importar si la pública
     * queda más cerca o más barata.
     */
    public function forClient(User $client, ?float $originLat, ?float $originLng): Collection
    {
        $addedCooperativeIds = ClientCooperative::query()
            ->where('client_user_id', $client->id)
            ->pluck('cooperative_id');

        return Cooperative::query()
            ->where('status', 'approved')
            ->whereNull('suspended_at')
            ->where(fn ($query) => $query
                ->whereIn('id', $addedCooperativeIds)
                ->orWhere('is_public', true))
            ->with('activeDriverMemberships.driver.driverProfile')
            ->withCount('activeDriverMemberships')
            ->orderBy('name')
            ->get(['id', 'public_id', 'name', 'logo_path', 'response_timeout_seconds', 'stand_lat', 'stand_lng', 'max_request_distance_km', 'rate_per_km', 'driver_pay_rate_per_km', 'is_public'])
            ->map(function ($cooperative) use ($originLat, $originLng, $addedCooperativeIds) {
                $cooperative->distance_km = $originLat !== null && $originLng !== null && $cooperative->stand_lat && $cooperative->stand_lng
                    ? round(Haversine::distanceKm($originLat, $originLng, (float) $cooperative->stand_lat, (float) $cooperative->stand_lng), 1)
                    : null;
                $cooperative->average_rate_per_km = round((float) $cooperative->activeDriverMemberships
                    ->pluck('driver.driverProfile.rate_per_km')->filter()->avg(), 2);
                $cooperative->effective_rate_per_km = $cooperative->rate_per_km !== null
                    ? (float) $cooperative->rate_per_km
                    : $cooperative->average_rate_per_km;
                $cooperative->is_added = $addedCooperativeIds->contains($cooperative->id);

                return $cooperative;
            })
            ->filter(fn ($cooperative) => $cooperative->max_request_distance_km === null
                || $cooperative->distance_km === null
                || $cooperative->distance_km <= $cooperative->max_request_distance_km)
            ->sortBy(fn ($cooperative) => $cooperative->distance_km ?? PHP_FLOAT_MAX)->values();
    }
}
