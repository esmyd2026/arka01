<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pedido de un cliente para sumarse (compartir gastos) al Expreso de otro
 * cliente, porque su ruta y horario le calzan cerca — ver ExpressRoute.
 */
class ExpressRouteCompanion extends Model
{
    use HasFactory;

    protected $fillable = [
        'express_route_id',
        'passenger_user_id',
        'origin_lat',
        'origin_lng',
        'origin_address',
        'destination_lat',
        'destination_lng',
        'destination_address',
        'status',
        'driver_approval_status',
        'requested_at',
        'responded_at',
        'driver_responded_at',
    ];

    protected $casts = [
        'origin_lat' => 'decimal:7',
        'origin_lng' => 'decimal:7',
        'destination_lat' => 'decimal:7',
        'destination_lng' => 'decimal:7',
        'requested_at' => 'datetime',
        'responded_at' => 'datetime',
        'driver_responded_at' => 'datetime',
    ];

    public function route(): BelongsTo
    {
        return $this->belongsTo(ExpressRoute::class, 'express_route_id');
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'passenger_user_id');
    }
}
