<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_type',
        'code',
        'name',
        'monthly_price',
        'max_clients',
        'public_visibility',
        'priority_listing',
        'verified_badge',
        // Módulo de viajes tipo VAN/turismo (pedido explícito del usuario):
        // "puede manejarse como un plan premium exclusivo para conductores".
        'van_trips_enabled',
        // Módulo de Expresos (pedido explícito del usuario): a diferencia de
        // VAN, ya era una función abierta — este flag permite RESTRINGIRLA
        // por plan, no habilitarla desde cero.
        'express_enabled',
        'max_fleets',
        'max_drivers_per_fleet',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'monthly_price' => 'decimal:2',
        'public_visibility' => 'boolean',
        'priority_listing' => 'boolean',
        'verified_badge' => 'boolean',
        'van_trips_enabled' => 'boolean',
        'express_enabled' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function isForDrivers(): bool
    {
        return $this->owner_type === 'driver';
    }

    public function isForClients(): bool
    {
        return $this->owner_type === 'client';
    }
}
