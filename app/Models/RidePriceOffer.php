<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RidePriceOffer extends Model
{
    use HasFactory;

    protected $fillable = [
        'ride_request_id',
        'offered_by_user_id',
        'offered_amount',
    ];

    protected $casts = [
        'offered_amount' => 'decimal:2',
    ];

    public function rideRequest(): BelongsTo
    {
        return $this->belongsTo(RideRequest::class);
    }

    public function offeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'offered_by_user_id');
    }
}
