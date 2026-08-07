<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'subscription_plan_id',
        'status',
        'started_at',
        'expires_at',
        'custom_max_clients',
        'custom_max_fleets',
        'custom_max_drivers_per_fleet',
        'activated_by',
        'note',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    /**
     * "active" o "grace" cuentan como vigente para los límites del plan
     * (sección 9.6: durante la gracia todavía no se degrada a Gratis).
     */
    public function isUsable(): bool
    {
        return in_array($this->status, ['active', 'grace'], true);
    }
}
