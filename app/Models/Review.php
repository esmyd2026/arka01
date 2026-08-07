<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    use HasFactory;

    protected $fillable = [
        'ride_id',
        'reviewer_user_id',
        'reviewee_user_id',
        'rating',
        'rating_reason_id',
        'comment',
    ];

    protected $casts = [
        'rating' => 'integer',
    ];

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }

    public function reviewee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewee_user_id');
    }

    public function ratingReason(): BelongsTo
    {
        return $this->belongsTo(RatingReason::class);
    }
}
