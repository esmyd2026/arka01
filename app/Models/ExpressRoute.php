<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Un "Expreso" (sección 4): ruta fija y recurrente que un cliente publica,
 * con horario, condiciones y precio pactados de antemano. Genera una
 * solicitud de carrera automáticamente cada día que corresponda, una vez que
 * tiene un conductor asignado (ver App\Console\Commands\GenerateExpressRides).
 */
class ExpressRoute extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_user_id',
        'name',
        'origin_lat',
        'origin_lng',
        'origin_address',
        'destination_lat',
        'destination_lng',
        'destination_address',
        'days_of_week',
        'departure_time',
        'is_round_trip',
        'return_time',
        'offered_price',
        'status',
        'assigned_driver_user_id',
        'assigned_at',
        // Compartir el Expreso con otros clientes de ruta parecida (pedido
        // explícito del usuario): reparte el costo y hace que al conductor
        // sí le convenga, en vez de ir vacío a buscar a una sola persona.
        'share_enabled',
        'max_companions',
    ];

    protected $casts = [
        'origin_lat' => 'decimal:7',
        'origin_lng' => 'decimal:7',
        'destination_lat' => 'decimal:7',
        'destination_lng' => 'decimal:7',
        'days_of_week' => 'array',
        'is_round_trip' => 'boolean',
        'offered_price' => 'decimal:2',
        'assigned_at' => 'datetime',
        'share_enabled' => 'boolean',
        'max_companions' => 'integer',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_user_id');
    }

    public function assignedDriver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_driver_user_id');
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(ExpressCondition::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(ExpressApplication::class);
    }

    /**
     * Todas las solicitudes de carrera que este Expreso generó día a día
     * (sección 4.2) — el historial del contrato.
     */
    public function rideRequests(): HasMany
    {
        return $this->hasMany(RideRequest::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(ExpressIncident::class);
    }

    public function companions(): HasMany
    {
        return $this->hasMany(ExpressRouteCompanion::class);
    }

    public function isOpenForApplications(): bool
    {
        return $this->status === 'open';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function acceptedCompanionsCount(): int
    {
        return $this->companions()->where('status', 'accepted')->count();
    }

    /**
     * true si todavía hay lugar para otro acompañante — sin `max_companions`
     * configurado, se asume 1 (el dueño ya definió que comparte, pero sin
     * decir cuántos, así que se limita a uno por defecto en vez de dejarlo
     * sin tope).
     */
    public function hasRoomForCompanions(): bool
    {
        if (! $this->share_enabled) {
            return false;
        }

        $limit = $this->max_companions ?? 1;

        return $this->acceptedCompanionsCount() < $limit;
    }

    /**
     * Precio por persona si el Expreso se reparte entre el dueño y sus
     * acompañantes aceptados (pedido explícito del usuario: "que eso le
     * ayude a bajar un poco el costo de la carrera") — el total pactado con
     * el conductor no cambia, cada quien paga su parte proporcional.
     */
    public function pricePerPerson(): float
    {
        return round((float) $this->offered_price / (1 + $this->acceptedCompanionsCount()), 2);
    }

    /**
     * true si corresponde generar una carrera hoy (sección 4.2), según los
     * días de la semana pactados. 0=domingo..6=sábado (Carbon::dayOfWeek).
     */
    public function runsOn(Carbon $date): bool
    {
        return in_array($date->dayOfWeek, $this->days_of_week, true);
    }
}
