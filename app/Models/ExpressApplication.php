<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpressApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'express_route_id',
        'driver_user_id',
        'proposed_price',
        'status',
        'applied_at',
        'responded_at',
    ];

    protected $casts = [
        'proposed_price' => 'decimal:2',
        'applied_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    public function route(): BelongsTo
    {
        return $this->belongsTo(ExpressRoute::class, 'express_route_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_user_id');
    }
}
