<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catálogo de ciudades para las "zonas del Ecuador" (consideración agregada
 * al alcance) — administrable desde el panel admin, no quemado en código.
 */
class City extends Model
{
    protected $fillable = ['name', 'province', 'lat', 'lng', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
        'lat' => 'decimal:7',
        'lng' => 'decimal:7',
    ];

    public function sectors(): HasMany
    {
        return $this->hasMany(Sector::class);
    }
}
