<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una parada intermedia de una solicitud de carrera (pedido explícito del
 * usuario: hasta 4, cada una con su propio tramo/precio). Se copia a
 * `RideStop` cuando un conductor acepta — ver RideRequestController::accept().
 */
class RideRequestStop extends Model
{
    use HasFactory;

    protected $fillable = [
        'ride_request_id',
        'sequence',
        'lat',
        'lng',
        'address',
        'sector_id',
        'leg_distance_km',
        'leg_price',
    ];

    protected $casts = [
        'lat' => 'decimal:7',
        'lng' => 'decimal:7',
        'leg_distance_km' => 'decimal:2',
        'leg_price' => 'decimal:2',
    ];

    public function rideRequest(): BelongsTo
    {
        return $this->belongsTo(RideRequest::class);
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }
}
