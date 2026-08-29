<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RideRequest extends Model
{
    use HasFactory;

    // La auditoría completa contiene puntajes internos de todos los
    // candidatos. Se conserva para operación/soporte, pero no se serializa
    // accidentalmente hacia clientes o conductores.
    protected $hidden = ['smart_dispatch_snapshot'];

    protected $fillable = [
        'fleet_id',
        'cooperative_id',
        'cooperative_assignment_status',
        'cooperative_candidate_ids',
        'cooperative_offer_expires_at',
        'cooperative_dispatch_log',
        'express_route_id',
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
        // Cargo por trayecto de recogida (pedido explícito del usuario): el
        // monto PROPUESTO al candidato actual — se recalcula en cada salto
        // del despacho secuencial (ver App\Services\RideDispatchAdvancer),
        // todavía no es una decisión tomada. El conductor recién elige
        // cobrarlo o no al aceptar (ver `rides.pickup_fare_charged`).
        'pickup_distance_km',
        'pickup_fare',
        'payment_method',
        'status',
        'current_offered_price',
        'stops_price',
        'negotiation_round',
        'last_offer_made_by',
        'negotiating_driver_user_id',
        'accepted_by',
        'requested_at',
        'responded_at',
        'is_scheduled',
        'scheduled_at',
        'round_trip',
        'dispatch_pool',
        'offer_candidate_ids',
        'current_offer_expires_at',
        'smart_dispatch_version',
        'smart_dispatch_snapshot',
        'passenger_count',
        'needs_trunk',
        'notes',
    ];

    protected $casts = [
        'origin_lat' => 'decimal:7',
        'origin_lng' => 'decimal:7',
        'destination_lat' => 'decimal:7',
        'destination_lng' => 'decimal:7',
        'distance_km' => 'decimal:2',
        'pickup_distance_km' => 'decimal:2',
        'pickup_fare' => 'decimal:2',
        'current_offered_price' => 'decimal:2',
        'stops_price' => 'decimal:2',
        'negotiation_round' => 'integer',
        'requested_at' => 'datetime',
        'responded_at' => 'datetime',
        'is_scheduled' => 'boolean',
        'scheduled_at' => 'datetime',
        'round_trip' => 'boolean',
        'offer_candidate_ids' => 'array',
        'current_offer_expires_at' => 'datetime',
        'smart_dispatch_snapshot' => 'array',
        'cooperative_candidate_ids' => 'array',
        'cooperative_offer_expires_at' => 'datetime',
        'cooperative_dispatch_log' => 'array',
        'passenger_count' => 'integer',
        'needs_trunk' => 'boolean',
    ];

    public function fleet(): BelongsTo
    {
        return $this->belongsTo(Fleet::class);
    }

    public function cooperative(): BelongsTo
    {
        return $this->belongsTo(Cooperative::class);
    }

    /**
     * Sector de origen/destino (ej. "Sauces 1" → "Samanes 3"): además del
     * mapa, para que el conductor entienda de un vistazo dónde está el
     * cliente y a dónde va, sin tener que abrirlo (consideración agregada
     * al alcance).
     */
    public function originSector(): BelongsTo
    {
        return $this->belongsTo(Sector::class, 'origin_sector_id');
    }

    public function destinationSector(): BelongsTo
    {
        return $this->belongsTo(Sector::class, 'destination_sector_id');
    }

    /**
     * Si no es null, esta solicitud nació de la generación automática diaria
     * de un Expreso (sección 4.2), no de un pedido manual del cliente.
     */
    public function expressRoute(): BelongsTo
    {
        return $this->belongsTo(ExpressRoute::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_user_id');
    }

    /**
     * El conductor puntual al que se le mandó la solicitud. Es null cuando
     * se mandó a "toda la flota disponible" (sección 3.5).
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_user_id');
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    /**
     * El conductor que "tomó" la negociación (relevante sobre todo cuando la
     * solicitud se mandó a toda la flota: el primero que responde —acepta o
     * contraoferta— es el único que sigue viendo/respondiendo esta solicitud).
     */
    public function negotiatingDriver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'negotiating_driver_user_id');
    }

    /**
     * Bitácora de ofertas de precio (sección 5): el monto inicial del cliente
     * y, si la hubo, la única contraoferta permitida del conductor.
     */
    public function priceOffers(): HasMany
    {
        return $this->hasMany(RidePriceOffer::class);
    }

    /**
     * La carrera confirmada que salió de esta solicitud, si ya fue aceptada.
     */
    public function ride(): HasOne
    {
        return $this->hasOne(Ride::class);
    }

    /**
     * Paradas intermedias (pedido explícito del usuario: hasta 4, cada una
     * con su propio tramo/precio) — se copian a Ride::stops() al aceptar,
     * ver RideRequestController::accept().
     */
    public function stops(): HasMany
    {
        return $this->hasMany(RideRequestStop::class)->orderBy('sequence');
    }

    /**
     * true si hay alguien puntual respondiendo esta solicitud ahora mismo —
     * ya sea porque el cliente la dirigió a propósito (`dispatch_pool` null),
     * o porque le toca el turno dentro de una tanda secuencial estilo Uber
     * (consideración agregada al alcance, pedido explícito del usuario: "que
     * sea al más cercano... y si no responde en 30s se pasa a otro"). En los
     * dos casos, solo esa persona puede aceptar/contraofertar/rechazar — por
     * eso toda la autorización que ya usaba este método sigue funcionando
     * igual sin tocarla, ver App\Services\RideDispatchAdvancer.
     */
    public function isDirected(): bool
    {
        return ! is_null($this->driver_user_id);
    }

    /**
     * true si esta solicitud viene de una "bolsa" (mi flota / público /
     * ambos) en vez de haber sido dirigida a propósito por el cliente — solo
     * estas tienen cascada y vencimiento de 30 segundos.
     */
    public function isSequentialDispatch(): bool
    {
        return ! is_null($this->dispatch_pool);
    }

    /**
     * true si el conductor ya usó su única ronda de contraoferta permitida
     * (sección 5: "se sugiere limitar a una ronda para no alargar el proceso").
     */
    public function driverHasUsedItsCounterOffer(): bool
    {
        return $this->negotiation_round >= 1;
    }

    /**
     * Solicitudes pendientes que le llegan a ESTE conductor — dirigidas a él
     * puntualmente, o sin conductor asignado todavía pero de una flota donde
     * es miembro activo (misma lógica que ya usaba
     * DashboardController::incomingRequestsQuery(), movida acá para
     * reusarla también desde HandleInertiaRequests::share() — el badge de
     * la pestaña "Carreras" de la nav, pedido explícito del usuario). No
     * aplica el refinamiento fino de zona de cobertura ni flotas con
     * solicitudes deshabilitadas que sí hace /carreras — para un contador
     * de nav alcanza con esto, no hace falta que sea perfecto al dígito.
     */
    public static function pendingIncomingFor(int $driverUserId): Builder
    {
        return self::query()
            ->where('status', 'pending')
            ->where(function ($query) use ($driverUserId) {
                $query->where('driver_user_id', $driverUserId)
                    ->orWhere(function ($query) use ($driverUserId) {
                        $query->whereNull('driver_user_id')
                            ->whereIn('fleet_id', function ($sub) use ($driverUserId) {
                                $sub->select('fleet_id')
                                    ->from('fleet_members')
                                    ->where('driver_user_id', $driverUserId)
                                    ->whereNull('left_at');
                            });
                    });
            });
    }
}
