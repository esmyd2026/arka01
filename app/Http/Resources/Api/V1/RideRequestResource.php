<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Estado de una solicitud de carrera (RideRequest) para el cliente móvil —
 * pantalla "Solicitar carrera" (ROADMAP_APLICACION_MOVIL_CAPACITOR.md,
 * Hito 5). Igual que FleetResource, nunca serializa el modelo Eloquent
 * crudo (evita filtrar `smart_dispatch_snapshot`, ids internos, etc.).
 */
class RideRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'is_scheduled' => (bool) $this->is_scheduled,
            'scheduled_at' => $this->scheduled_at,
            'origin_address' => $this->origin_address,
            'origin_lat' => (float) $this->origin_lat,
            'origin_lng' => (float) $this->origin_lng,
            'destination_address' => $this->destination_address,
            'destination_lat' => (float) $this->destination_lat,
            'destination_lng' => (float) $this->destination_lng,
            'distance_km' => $this->distance_km !== null ? (float) $this->distance_km : null,
            'current_offered_price' => (float) $this->current_offered_price,
            'payment_method' => $this->payment_method,
            'dispatch_pool' => $this->dispatch_pool,
            'passenger_count' => $this->passenger_count,
            'needs_trunk' => (bool) $this->needs_trunk,
            'notes' => $this->notes,
            'driver' => $this->when($this->driver_user_id !== null, fn () => [
                'user_id' => $this->driver->public_id,
                'name' => $this->driver->full_name,
                'avatar_url' => $this->driver->avatar_url,
                'rate_per_km' => $this->driver->driverProfile?->rate_per_km,
            ]),
            'current_offer_expires_at' => $this->current_offer_expires_at,
            'requested_at' => $this->requested_at,
            // Una vez aceptada, la Ride real que se crea (ver
            // App\Services\Ride\RideRequestResponder::accept()) — el
            // cliente móvil la usa para saber a qué pantalla de seguimiento
            // pasar cuando el estado cambia a "accepted". Id crudo, no
            // public_id: /api/v1/rides/{ride} se resuelve por el id de
            // siempre (mismo criterio que /api/v1/ride-requests/{id}).
            'ride_id' => $this->whenLoaded('ride', fn () => $this->ride?->id),
        ];
    }
}
