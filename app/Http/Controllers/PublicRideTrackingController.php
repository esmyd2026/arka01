<?php

namespace App\Http\Controllers;

use App\Models\Ride;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Seguimiento en vivo compartible (sección 8): un enlace de solo lectura,
 * sin cuenta ni instalación, para que un contacto de confianza vea la
 * ubicación del conductor en un mapa. Protegido por firma temporal de
 * Laravel (middleware 'signed'), no por autenticación — por eso nunca
 * expone más datos de los necesarios para ubicar la carrera en el mapa.
 */
class PublicRideTrackingController extends Controller
{
    public function show(Ride $ride): Response
    {
        $ride->load(['driver.driverProfile']);

        return Inertia::render('Public/RideTracking', [
            'ride' => $this->publicPayload($ride),
            // Se re-firma acá mismo para que el polling del frontend no
            // tenga que reconstruir la URL — hereda la misma validez que
            // esta visita ya demostró tener.
            'statusUrl' => URL::temporarySignedRoute(
                'public.rides.track.status',
                now()->addHours(24),
                ['ride' => $ride->id]
            ),
        ]);
    }

    public function status(Ride $ride): JsonResponse
    {
        return response()->json($this->publicPayload($ride));
    }

    /**
     * Solo lo mínimo para pintar el mapa y el estado — nunca precio, ni
     * teléfono, ni nada que no haga falta para que un contacto de confianza
     * siga el viaje (sección 8: link de "solo lectura").
     *
     * @return array<string, mixed>
     */
    private function publicPayload(Ride $ride): array
    {
        $profile = $ride->driver->driverProfile;

        return [
            'id' => $ride->id,
            'status' => $ride->status,
            'driver_name' => $ride->driver->name,
            'vehicle' => trim(($profile?->vehicle_make ?? '').' '.($profile?->vehicle_model ?? '')) ?: null,
            'vehicle_plate' => $profile?->vehicle_plate,
            'origin_lat' => $ride->origin_lat,
            'origin_lng' => $ride->origin_lng,
            'origin_address' => $ride->origin_address,
            'destination_lat' => $ride->destination_lat,
            'destination_lng' => $ride->destination_lng,
            'destination_address' => $ride->destination_address,
            'driver_lat' => $ride->status === 'in_progress' ? $profile?->current_lat : null,
            'driver_lng' => $ride->status === 'in_progress' ? $profile?->current_lng : null,
            'started_at' => $ride->started_at,
            'completed_at' => $ride->completed_at,
        ];
    }
}
