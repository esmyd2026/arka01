<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Viaje programado puntual de un conductor con vehículo tipo VAN/buseta/
 * microbús/turístico (pedido explícito del usuario) — a diferencia de un
 * Expreso, no es recurrente: es una salida concreta, en una fecha concreta,
 * con cupo de asientos que los clientes van reservando.
 */
class VanTrip extends Model
{
    use HasFactory;

    protected $fillable = [
        'driver_user_id',
        'origin_city_id',
        'destination_city_id',
        'travel_date',
        'departure_time',
        'total_seats',
        'price_per_seat',
        'description',
        'included_services',
        'luggage_allowance',
        'status',
    ];

    protected $casts = [
        'travel_date' => 'date',
        'total_seats' => 'integer',
        'price_per_seat' => 'decimal:2',
        'included_services' => 'array',
    ];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_user_id');
    }

    public function originCity(): BelongsTo
    {
        return $this->belongsTo(City::class, 'origin_city_id');
    }

    public function destinationCity(): BelongsTo
    {
        return $this->belongsTo(City::class, 'destination_city_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(VanTripPhoto::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(VanTripReservation::class);
    }

    public function seatsReserved(): int
    {
        return (int) $this->reservations()->where('status', 'confirmed')->sum('seats_reserved');
    }

    public function seatsAvailable(): int
    {
        return max(0, $this->total_seats - $this->seatsReserved());
    }

    public function isOpenForBooking(): bool
    {
        return $this->status === 'open' && ! $this->travel_date->isPast();
    }
}
