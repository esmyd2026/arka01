<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpressCondition extends Model
{
    use HasFactory;

    protected $fillable = [
        'express_route_id',
        'description',
    ];

    public function route(): BelongsTo
    {
        return $this->belongsTo(ExpressRoute::class, 'express_route_id');
    }
}
