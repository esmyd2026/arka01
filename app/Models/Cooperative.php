<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Cooperative extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'legal_name',
        'ruc',
        'main_address',
        'city_id',
        'province',
        'phone',
        'email',
        'logo_path',
        'legal_representative',
        'declared_driver_count',
        'declared_unit_count',
        'geographic_coverage',
        'operating_hours',
        'response_timeout_seconds',
    ];

    protected $casts = [
        'declared_driver_count' => 'integer',
        'declared_unit_count' => 'integer',
        'response_timeout_seconds' => 'integer',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'suspended_at' => 'datetime',
    ];

    protected $appends = ['logo_url'];

    protected static function booted(): void
    {
        static::created(fn (Cooperative $cooperative) => User::whereKey($cooperative->user_id)->update(['role' => 'cooperativa']));

        static::deleted(function (Cooperative $cooperative) {
            $user = User::find($cooperative->user_id);
            if ($user) {
                $user->forceFill(['role' => $user->is_admin ? 'admin' : ($user->isDriver() ? 'conductor' : 'cliente')])->save();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CooperativeDocument::class);
    }

    public function driverMemberships(): HasMany
    {
        return $this->hasMany(CooperativeDriverMembership::class);
    }

    public function activeDriverMemberships(): HasMany
    {
        return $this->driverMemberships()->where('status', 'accepted')->whereNull('ended_at');
    }

    public function clientLinks(): HasMany
    {
        return $this->hasMany(ClientCooperative::class);
    }

    public function rideRequests(): HasMany
    {
        return $this->hasMany(RideRequest::class);
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved' && $this->suspended_at === null;
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }
}
