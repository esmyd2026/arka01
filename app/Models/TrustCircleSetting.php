<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrustCircleSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'connection_id',
        'user_id',
        'relationship_label',
        'share_fleet',
        'share_rating',
    ];

    protected $casts = [
        'share_fleet' => 'boolean',
        'share_rating' => 'boolean',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(TrustCircleConnection::class, 'connection_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
