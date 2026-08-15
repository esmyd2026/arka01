<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CooperativeDriverMembership extends Model
{
    use HasFactory;

    protected $fillable = [
        'cooperative_id',
        'driver_user_id',
        'invited_by_user_id',
        'status',
        'responded_at',
        'suspended_at',
        'ended_at',
    ];

    protected $casts = [
        'responded_at' => 'datetime',
        'suspended_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function cooperative(): BelongsTo
    {
        return $this->belongsTo(Cooperative::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_user_id');
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }
}
