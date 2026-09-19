<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Recorte del modelo User para la API móvil (roadmap Hito 2: "no exponer
 * modelos Eloquent directamente") — solo lo que la app necesita mostrar,
 * nunca columnas internas nuevas por accidente cada vez que el modelo crece.
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'birth_date' => $this->birth_date?->toDateString(),
            'city_id' => $this->city_id,
            'city_name' => $this->whenLoaded('city', fn () => $this->city?->name),
            'role' => $this->role,
            'avatar_url' => $this->avatar_url,
            'public_profile_url' => $this->public_profile_url,
        ];
    }
}
