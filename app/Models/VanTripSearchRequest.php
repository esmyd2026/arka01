<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Búsqueda de un cliente en "Rutas y Turismo" que no encontró ningún viaje
 * abierto (pedido explícito del usuario): queda guardada para poder
 * mostrarle a los conductores qué rutas están pidiendo, aunque todavía
 * nadie haya publicado un viaje que las cubra — ver
 * VanTripController::browse() (quien guarda) e index() (quien la lista).
 */
class VanTripSearchRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'origin_city_id',
        'destination_city_id',
        'travel_date',
    ];

    protected $casts = [
        'travel_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function originCity(): BelongsTo
    {
        return $this->belongsTo(City::class, 'origin_city_id');
    }

    public function destinationCity(): BelongsTo
    {
        return $this->belongsTo(City::class, 'destination_city_id');
    }
}
