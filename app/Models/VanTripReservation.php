<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reserva de uno o más asientos de un VanTrip por parte de un cliente
 * (pedido explícito del usuario) — precio fijo por asiento, sin negociación.
 */
class VanTripReservation extends Model
{
    use HasFactory;

    protected $fillable = [
        'van_trip_id',
        'client_user_id',
        'seats_reserved',
        'status',
        'reserved_at',
    ];

    protected $casts = [
        'seats_reserved' => 'integer',
        'reserved_at' => 'datetime',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(VanTrip::class, 'van_trip_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_user_id');
    }
}
