<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpressRouteCompanionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'express_route_id' => $this->express_route_id,
            'status' => $this->status,
            'driver_approval_status' => $this->driver_approval_status,
            'origin_address' => $this->origin_address,
            'destination_address' => $this->destination_address,
            'requested_at' => $this->requested_at,
            'responded_at' => $this->responded_at,
            'passenger' => $this->whenLoaded('passenger', fn () => [
                'name' => $this->passenger->full_name,
                'avatar_url' => $this->passenger->avatar_url,
            ]),
            'route' => $this->whenLoaded('route', fn () => [
                'id' => $this->route->id,
                'name' => $this->route->name,
                'origin_address' => $this->route->origin_address,
                'destination_address' => $this->route->destination_address,
                'departure_time' => $this->route->departure_time,
                'client' => $this->when($this->route->relationLoaded('client'), fn () => [
                    'name' => $this->route->client->full_name,
                    'avatar_url' => $this->route->client->avatar_url,
                ]),
            ]),
        ];
    }
}
