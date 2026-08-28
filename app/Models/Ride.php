<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Ride extends Model
{
    use HasFactory;

    protected $fillable = [
        'ride_request_id',
        'fleet_id',
        'client_user_id',
        'driver_user_id',
        'origin_lat',
        'origin_lng',
        'origin_address',
        'origin_sector_id',
        'destination_lat',
        'destination_lng',
        'destination_address',
        'destination_sector_id',
        'distance_km',
        'payment_method',
        'notes',
        'round_trip',
        'rate_per_km_snapshot',
        'price',
        'stops_price',
        'settled_price',
        'points_earned',
        'status',
        'started_at',
        'heading_to_passenger_at',
        'arrived_at',
        'picked_up_at',
        'completed_at',
        'completion_reason',
        'completion_note',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
        'cancellation_note',
        'pending_reschedule_at',
        'driver_reminder_sent_at',
        'overdue_alert_sent_at',
    ];

    protected $casts = [
        'origin_lat' => 'decimal:7',
        'origin_lng' => 'decimal:7',
        'destination_lat' => 'decimal:7',
        'destination_lng' => 'decimal:7',
        'distance_km' => 'decimal:2',
        'round_trip' => 'boolean',
        'rate_per_km_snapshot' => 'decimal:2',
        'price' => 'decimal:2',
        'stops_price' => 'decimal:2',
        'settled_price' => 'decimal:2',
        'points_earned' => 'integer',
        'started_at' => 'datetime',
        'heading_to_passenger_at' => 'datetime',
        'arrived_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'pending_reschedule_at' => 'datetime',
        'driver_reminder_sent_at' => 'datetime',
        'overdue_alert_sent_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Ride $ride) {
            $ride->public_id ??= (string) Str::uuid();
        });
    }

    /**
     * true si esta carrera viene de una solicitud PROGRAMADA que un
     * conductor ya aceptó pero todavía no arrancó (sección agregada al
     * alcance) — no cuenta como "en carrera" en ningún cálculo de
     * disponibilidad (esos ya filtran por status = 'in_progress' a propósito).
     */
    public function isScheduledAndNotStarted(): bool
    {
        return $this->status === 'scheduled';
    }

    /**
     * true si el cliente propuso otro horario y todavía nadie (el
     * conductor) lo confirmó ni lo rechazó — ver RideController::propose/
     * confirm/rejectReschedule().
     */
    public function hasPendingReschedule(): bool
    {
        return $this->pending_reschedule_at !== null;
    }

    public function rideRequest(): BelongsTo
    {
        return $this->belongsTo(RideRequest::class);
    }

    public function fleet(): BelongsTo
    {
        return $this->belongsTo(Fleet::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_user_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_user_id');
    }

    public function originSector(): BelongsTo
    {
        return $this->belongsTo(Sector::class, 'origin_sector_id');
    }

    public function destinationSector(): BelongsTo
    {
        return $this->belongsTo(Sector::class, 'destination_sector_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(RideMessage::class);
    }

    /**
     * Paradas intermedias (pedido explícito del usuario: hasta 4, cada una
     * completada/cancelada por separado) — ver RideController::completeStop().
     */
    public function stops(): HasMany
    {
        return $this->hasMany(RideStop::class)->orderBy('sequence');
    }

    /**
     * El chat (sección 10 del roadmap de mejoras) solo existe mientras hay
     * una relación de viaje vigente entre cliente y conductor — nunca antes
     * de que el conductor acepte, ni después de que termine o se cancele.
     */
    public function chatIsOpen(): bool
    {
        return in_array($this->status, ['scheduled', 'in_progress'], true);
    }
}
