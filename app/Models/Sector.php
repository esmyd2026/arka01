<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sector/barrio dentro de una ciudad (ej. "Sauces 1" en Guayaquil) — ver
 * App\Models\City. Se usa para indicar origen/destino de una carrera sin
 * tener que abrir el mapa (consideración agregada al alcance).
 */
class Sector extends Model
{
    protected $fillable = ['city_id', 'name', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
}
