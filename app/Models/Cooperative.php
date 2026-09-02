<?php

namespace App\Models;

use App\Services\Haversine;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Cooperative extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'legal_name',
        'ruc',
        'main_address',
        'stand_lat',
        'stand_lng',
        'city_id',
        'province',
        'phone',
        'email',
        'logo_path',
        'legal_representative',
        'declared_driver_count',
        'declared_unit_count',
        'has_insurance',
        'geographic_coverage',
        // Tarifa y reparto con conductores (pedido explícito del usuario):
        // 'rate_per_km' es lo que la cooperativa cobra al cliente,
        // 'driver_pay_rate_per_km' lo que le paga a sus conductores — la
        // diferencia es su margen. Ambas nullable: mientras no se
        // configuren, el precio sigue siendo el promedio de tarifas de los
        // conductores miembros, igual que hoy (ver RideRequestCreator::create()).
        'rate_per_km',
        'driver_pay_rate_per_km',
        // Rango de cobertura (pedido explícito del usuario): mismo concepto
        // que DriverProfile.max_request_distance_km, medido desde el
        // "stand" de la cooperativa — ver RideRequestController::create().
        'max_request_distance_km',
        'operating_hours',
        'response_timeout_seconds',
        'automatic_assignment_enabled',
        'manual_assignment_timeout_seconds',
        'whatsapp_ride_actions_enabled',
        'show_fleet_publicly',
        // Pedido explícito del usuario: prendido a mano por un admin desde
        // /admin/cooperativas, hace que esta cooperativa aparezca en "Elige
        // tu conductor" de CUALQUIER cliente, sin que la haya agregado a su
        // lista (ver ClientCooperative) — ver RideRequestController::create().
        'is_public',
    ];

    protected $casts = [
        'stand_lat' => 'decimal:7',
        'stand_lng' => 'decimal:7',
        'rate_per_km' => 'decimal:2',
        'driver_pay_rate_per_km' => 'decimal:2',
        'max_request_distance_km' => 'integer',
        'automatic_assignment_enabled' => 'boolean',
        'has_insurance' => 'boolean',
        'show_fleet_publicly' => 'boolean',
        'is_public' => 'boolean',
        'manual_assignment_timeout_seconds' => 'integer',
        'declared_driver_count' => 'integer',
        'declared_unit_count' => 'integer',
        'response_timeout_seconds' => 'integer',
        'whatsapp_ride_actions_enabled' => 'boolean',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'suspended_at' => 'datetime',
    ];

    protected $appends = ['logo_url', 'public_url'];

    protected static function booted(): void
    {
        static::creating(function (Cooperative $cooperative) {
            $cooperative->public_id ??= (string) Str::uuid();
        });

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

    public function walletEntries(): HasMany
    {
        return $this->hasMany(CooperativeWalletEntry::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(CooperativeBankAccount::class)
            ->orderByDesc('is_favorite')
            ->orderByDesc('id');
    }

    /**
     * Zona de cobertura (pedido explícito del usuario): true si un punto
     * (origen de una carrera) cae dentro de la distancia máxima que esta
     * cooperativa configuró desde su "stand" — mismo criterio que
     * DriverProfile::isWithinRangeOf(). Sin límite configurado, o sin
     * ubicación de base conocida todavía, no hay nada que descartar.
     */
    public function isWithinRangeOf(float $originLat, float $originLng): bool
    {
        if ($this->max_request_distance_km === null || $this->stand_lat === null || $this->stand_lng === null) {
            return true;
        }

        $distanceKm = Haversine::distanceKm($originLat, $originLng, (float) $this->stand_lat, (float) $this->stand_lng);

        return $distanceKm <= $this->max_request_distance_km;
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved' && $this->suspended_at === null;
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }

    public function getPublicUrlAttribute(): ?string
    {
        return $this->public_id ? route('cooperatives.show', $this->public_id) : null;
    }
}
