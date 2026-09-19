<?php

namespace App\Http\Resources\Api\V1;

use App\Models\RatingReason;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una carrera ya aceptada para el cliente móvil (ROADMAP_APLICACION_MOVIL_CAPACITOR.md,
 * Hito 5). `is_driver` le dice al frontend si mostrar los botones de acción
 * del conductor (arrancar/llegué/recogido/completar) o la vista de
 * seguimiento del cliente.
 */
class RideResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'status' => $this->status,
            'is_driver' => $this->driver_user_id === $request->user()->id,
            'origin_address' => $this->origin_address,
            'origin_lat' => (float) $this->origin_lat,
            'origin_lng' => (float) $this->origin_lng,
            'destination_address' => $this->destination_address,
            'destination_lat' => (float) $this->destination_lat,
            'destination_lng' => (float) $this->destination_lng,
            'distance_km' => $this->distance_km !== null ? (float) $this->distance_km : null,
            'price' => (float) $this->price,
            'payment_method' => $this->payment_method,
            'notes' => $this->notes,
            'started_at' => $this->started_at,
            'heading_to_passenger_at' => $this->heading_to_passenger_at,
            'arrived_at' => $this->arrived_at,
            'picked_up_at' => $this->picked_up_at,
            'completed_at' => $this->completed_at,
            'cancelled_at' => $this->cancelled_at,
            'cancellation_reason' => $this->cancellation_reason,
            'client' => [
                'name' => $this->client->full_name,
                'avatar_url' => $this->client->avatar_url,
            ],
            'driver' => [
                'name' => $this->driver->full_name,
                'avatar_url' => $this->driver->avatar_url,
                'rate_per_km' => $this->driver->driverProfile?->rate_per_km,
                'current_lat' => $this->driver->driverProfile?->current_lat !== null ? (float) $this->driver->driverProfile->current_lat : null,
                'current_lng' => $this->driver->driverProfile?->current_lng !== null ? (float) $this->driver->driverProfile->current_lng : null,
                'location_updated_at' => $this->driver->driverProfile?->location_updated_at,
                'vehicle_make' => $this->driver->driverProfile?->vehicle_make,
                'vehicle_model' => $this->driver->driverProfile?->vehicle_model,
                'vehicle_color' => $this->driver->driverProfile?->vehicle_color,
                'vehicle_plate' => $this->driver->driverProfile?->vehicle_plate,
            ],
            'has_reviewed' => Review::query()->where('ride_id', $this->id)->where('reviewer_user_id', $request->user()->id)->exists(),
            'rating_reasons' => RatingReason::query()
                ->where('direction', $this->client_user_id === $request->user()->id ? 'client_to_driver' : 'driver_to_client')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'text']),
        ];
    }
}
