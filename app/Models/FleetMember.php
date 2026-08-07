<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'fleet_id',
        'driver_user_id',
        'added_by',
        'joined_at',
        'left_at',
        'left_reason',
        'removed_by',
        'requests_disabled',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
        'requests_disabled' => 'boolean',
    ];

    public function fleet(): BelongsTo
    {
        return $this->belongsTo(Fleet::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_user_id');
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }

    /**
     * true si la membresía sigue vigente (nadie la cerró todavía).
     */
    public function isActive(): bool
    {
        return is_null($this->left_at);
    }
}
