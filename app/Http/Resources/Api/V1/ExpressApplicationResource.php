<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpressApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'express_route_id' => $this->express_route_id,
            'status' => $this->status,
            'proposed_price' => $this->proposed_price !== null ? (float) $this->proposed_price : null,
            'applied_at' => $this->applied_at,
            'responded_at' => $this->responded_at,
            'driver' => $this->whenLoaded('driver', fn () => [
                'name' => $this->driver->full_name,
                'avatar_url' => $this->driver->avatar_url,
            ]),
        ];
    }
}
