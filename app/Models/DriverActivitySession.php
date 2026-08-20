<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverActivitySession extends Model
{
    protected $fillable = ['driver_user_id', 'started_at', 'last_seen_at', 'ended_at', 'source'];

    protected $casts = [
        'started_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_user_id');
    }
}
