<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpressIncident extends Model
{
    use HasFactory;

    protected $fillable = [
        'express_route_id',
        'ride_id',
        'express_condition_id',
        'reported_by',
        'description',
    ];

    public function route(): BelongsTo
    {
        return $this->belongsTo(ExpressRoute::class, 'express_route_id');
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function condition(): BelongsTo
    {
        return $this->belongsTo(ExpressCondition::class, 'express_condition_id');
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}
