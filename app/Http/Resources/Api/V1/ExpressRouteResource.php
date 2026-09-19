<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un Expreso para el cliente móvil (roadmap app móvil, "full backend") —
 * nunca serializa el modelo Eloquent crudo.
 */
class ExpressRouteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'origin_address' => $this->origin_address,
            'origin_lat' => (float) $this->origin_lat,
            'origin_lng' => (float) $this->origin_lng,
            'destination_address' => $this->destination_address,
            'destination_lat' => (float) $this->destination_lat,
            'destination_lng' => (float) $this->destination_lng,
            'days_of_week' => $this->days_of_week,
            'departure_time' => $this->departure_time,
            'is_round_trip' => (bool) $this->is_round_trip,
            'return_time' => $this->return_time,
            'offered_price' => (float) $this->offered_price,
            'price_per_person' => (float) ($this->price_per_person ?? $this->pricePerPerson()),
            'share_enabled' => (bool) $this->share_enabled,
            'max_companions' => $this->max_companions,
            'pending_applications_count' => $this->when(isset($this->pending_applications_count), fn () => $this->pending_applications_count),
            'applications_count' => $this->when(isset($this->applications_count), fn () => $this->applications_count),
            // Solo presentes en el resultado de "descubrir para compartir"
            // (ExpressRouteCompanionResponder::discover()).
            'origin_distance_km' => $this->when(isset($this->origin_distance_km), fn () => $this->origin_distance_km),
            'destination_distance_km' => $this->when(isset($this->destination_distance_km), fn () => $this->destination_distance_km),
            'client' => $this->whenLoaded('client', fn () => [
                'name' => $this->client->full_name,
                'avatar_url' => $this->client->avatar_url,
            ]),
            'assigned_driver' => $this->whenLoaded('assignedDriver', fn () => $this->assignedDriver ? [
                'name' => $this->assignedDriver->full_name,
                'avatar_url' => $this->assignedDriver->avatar_url,
            ] : null),
            'conditions' => $this->whenLoaded('conditions', fn () => $this->conditions->pluck('description')),
            'my_application_status' => $this->when(
                $this->relationLoaded('applications') && $request->user()->isDriver(),
                fn () => $this->applications->firstWhere('driver_user_id', $request->user()->id)?->status,
            ),
            // Lista completa (no solo el conteo) — para que el dueño del
            // Expreso pueda revisar y aceptar/rechazar cada postulación
            // desde el detalle. Mismo criterio de "solo si está cargada"
            // que el resto de estas relaciones opcionales.
            'applications' => $this->when(
                $this->relationLoaded('applications') && $request->user()->id === $this->client_user_id,
                fn () => ExpressApplicationResource::collection($this->applications),
            ),
        ];
    }
}
