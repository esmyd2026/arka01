<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una solicitud de carrera que el conductor puede atender — extraído del
 * shape que arma App\Services\Ride\IncomingRideRequestFinder (roadmap app
 * móvil, Hito 5). Los campos `client_*` son atributos dinámicos que ese
 * servicio ya calcula (rating, código de socio), no columnas reales.
 */
class IncomingRideRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'is_directed' => $this->driver_user_id !== null,
            'origin_address' => $this->origin_address,
            'origin_lat' => (float) $this->origin_lat,
            'origin_lng' => (float) $this->origin_lng,
            'destination_address' => $this->destination_address,
            'destination_lat' => (float) $this->destination_lat,
            'destination_lng' => (float) $this->destination_lng,
            'distance_km' => $this->distance_km !== null ? (float) $this->distance_km : null,
            'current_offered_price' => (float) $this->current_offered_price,
            // Bug real reportado por el usuario: el conductor no veía las
            // paradas ni su parte del precio antes de aceptar — ver
            // IncomingRideRequestFinder::forDriver(), que ahora carga 'stops'
            // y calcula 'total_offered_price' (tramo final + paradas).
            'stops_price' => (float) ($this->stops_price ?? 0),
            'total_offered_price' => (float) $this->total_offered_price,
            'stops' => $this->stops->map(fn ($stop) => [
                'sequence' => $stop->sequence,
                'address' => $stop->address,
                'lat' => (float) $stop->lat,
                'lng' => (float) $stop->lng,
                'leg_distance_km' => $stop->leg_distance_km !== null ? (float) $stop->leg_distance_km : null,
                'leg_price' => (float) $stop->leg_price,
            ])->values(),
            'payment_method' => $this->payment_method,
            'passenger_count' => $this->passenger_count,
            'needs_trunk' => (bool) $this->needs_trunk,
            'notes' => $this->notes,
            'client' => [
                'name' => $this->client_name,
                'rating' => (float) $this->client_rating,
                'review_count' => $this->client_review_count,
                'member_code' => $this->client_member_code,
            ],
            'requested_at' => $this->requested_at,
            'current_offer_expires_at' => $this->current_offer_expires_at,
        ];
    }
}
