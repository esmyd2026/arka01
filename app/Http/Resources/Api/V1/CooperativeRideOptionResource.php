<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una cooperativa disponible para "Elige tu conductor" al pedir una carrera
 * — mismo shape que RideRequestController::cooperativesFor() calcula para
 * la web (App\Services\Ride\RideRequestCooperativeOptions), sin serializar
 * el modelo Eloquent crudo (mismo criterio que el resto de los Resource
 * móviles).
 */
class CooperativeRideOptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'logo_url' => $this->logo_url,
            'driver_count' => $this->active_driver_memberships_count,
            'distance_km' => $this->distance_km,
            'rate_per_km' => $this->effective_rate_per_km,
            'is_public' => (bool) $this->is_public,
            'is_added' => $this->is_added,
        ];
    }
}
