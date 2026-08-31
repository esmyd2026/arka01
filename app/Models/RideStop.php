<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una parada intermedia de una carrera ya aceptada (copiada desde
 * `RideRequestStop` en RideRequestController::accept()). Cada una se
 * completa o cancela por separado — ver RideController::completeStop().
 */
class RideStop extends Model
{
    use HasFactory;

    protected $fillable = [
        'ride_id',
        'sequence',
        'lat',
        'lng',
        'address',
        'sector_id',
        'leg_distance_km',
        'leg_price',
        'status',
        'completed_at',
        'cancelled_at',
        // Pedido explícito del usuario: completar lejos del punto exacto
        // debe permitir un motivo, igual que al completar el destino final
        // (ver RideLifecycle::EARLY_COMPLETION_REASONS).
        'completion_reason',
        'completion_note',
    ];

    protected $casts = [
        'lat' => 'decimal:7',
        'lng' => 'decimal:7',
        'leg_distance_km' => 'decimal:2',
        'leg_price' => 'decimal:2',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }
}
