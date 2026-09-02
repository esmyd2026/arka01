<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;
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
        // Cargo por trayecto de recogida (pedido explícito del usuario):
        // snapshot final de lo ya calculado en `ride_requests`, más la
        // decisión real del conductor al aceptar — ver
        // App\Services\Ride\RideRequestResponder::accept(). Columnas propias
        // (no mezcladas en `price`) para poder armar indicadores después.
        'pickup_distance_km',
        'pickup_fare',
        'pickup_fare_charged',
        'payment_method',
        'transfer_payment_notified_at',
        'payment_status',
        'payment_proof_path',
        'payment_proof_mime',
        'payment_proof_original_size',
        'payment_proof_stored_size',
        'payment_proof_uploaded_at',
        'payment_confirmed_at',
        'payment_confirmed_by_user_id',
        'payment_rejected_at',
        'payment_rejection_reason',
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
        'pickup_distance_km' => 'decimal:2',
        'pickup_fare' => 'decimal:2',
        'pickup_fare_charged' => 'boolean',
        'round_trip' => 'boolean',
        'transfer_payment_notified_at' => 'datetime',
        'payment_proof_uploaded_at' => 'datetime',
        'payment_confirmed_at' => 'datetime',
        'payment_rejected_at' => 'datetime',
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

        // El archivo está fuera de la base de datos. Si una carrera se borra
        // mediante Eloquent, elimínelo también para no dejar peso huérfano.
        static::deleted(function (Ride $ride) {
            if ($ride->payment_proof_path) {
                Storage::disk('local')->delete($ride->payment_proof_path);
            }
        });
    }

    /** El comprobante vive en almacenamiento privado; nunca se serializa. */
    protected $hidden = ['payment_proof_path'];

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

    public function paymentConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payment_confirmed_by_user_id');
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
     * Importe completo acordado para la carrera. `price` conserva el precio
     * del tramo final por compatibilidad con las carreras sin paradas;
     * `stops_price` contiene los tramos que terminan en cada parada.
     */
    public function quotedTotal(): float
    {
        return round((float) $this->price + (float) ($this->stops_price ?? 0), 2);
    }

    /**
     * Importe efectivamente cobrado. Una carrera cerrada antes de completar
     * todas sus paradas puede tener un `settled_price` menor al cotizado.
     */
    public function chargedTotal(): float
    {
        return $this->status === 'completed' && $this->settled_price !== null
            ? round((float) $this->settled_price, 2)
            : $this->quotedTotal();
    }

    /** Expresión SQL equivalente para indicadores y sumatorias. */
    public static function chargedTotalSql(): string
    {
        return 'COALESCE(settled_price, price + COALESCE(stops_price, 0))';
    }

    /**
     * Un solo movimiento de billetera por carrera de cooperativa completada
     * (ver RideLifecycle::recordCooperativeWalletEntry()) — nunca más de uno.
     */
    public function walletEntry(): HasOne
    {
        return $this->hasOne(CooperativeWalletEntry::class);
    }

    /**
     * Cuánto le correspondía en realidad al conductor por esta carrera de
     * cooperativa (pedido explícito del usuario: la tabla de trazabilidad
     * del equipo necesita esta columna, separada de lo que el cliente pagó).
     * El monto guardado en `walletEntry` es la DIFERENCIA que cambió de
     * manos de más, no el pago del conductor — se reconstruye a partir de
     * ella: en efectivo el conductor se quedó con todo y le debe el
     * excedente (su pago real es `chargedTotal() - amount`); por
     * transferencia la cooperativa se quedó con todo y el monto adeudado ES
     * directamente su pago real. Sin movimiento registrado (la cooperativa
     * no tenía sus tarifas configuradas en ese momento) no hubo reparto:
     * el conductor se quedó con el total cobrado.
     */
    public function cooperativeDriverPay(): float
    {
        $entry = $this->relationLoaded('walletEntry') ? $this->walletEntry : $this->walletEntry()->first();

        if (! $entry) {
            return $this->chargedTotal();
        }

        return $entry->direction === 'cooperative_owes_driver'
            ? (float) $entry->amount
            : round($this->chargedTotal() - (float) $entry->amount, 2);
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
