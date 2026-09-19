<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Estado de conexión de un conductor para la app móvil
 * (ROADMAP_APLICACION_MOVIL_CAPACITOR.md, Hito 5) — mismos campos que
 * DriverProfileController::edit() le da a Driver/Profile.vue
 * (`canConnect`/`connectionBlockReason`), para que el switch de la app
 * explique el mismo motivo de bloqueo que la web.
 */
class DriverStatusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'is_available' => (bool) $this->is_available,
            'can_connect' => $this->resource->canBecomeAvailable(),
            'connection_block_reason' => $this->resource->availabilityBlockReason(),
            'current_lat' => $this->current_lat !== null ? (float) $this->current_lat : null,
            'current_lng' => $this->current_lng !== null ? (float) $this->current_lng : null,
            'location_updated_at' => $this->location_updated_at,
            'rate_per_km' => $this->rate_per_km,
        ];
    }
}
