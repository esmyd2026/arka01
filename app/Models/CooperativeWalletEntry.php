<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pedido explícito del usuario: "esto se convierte en una billetera entre
 * cooperativa y conductor" — una fila por carrera de cooperativa completada
 * (ver App\Services\Ride\RideLifecycle::complete()), nunca un saldo
 * acumulado guardado aparte. El balance real se calcula sumando estas filas
 * (balanceFor(), más abajo) — mismo criterio que App\Models\WhatsAppSession
 * usa para no mantener un "status" que se pueda desincronizar del dato real.
 */
class CooperativeWalletEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'cooperative_id',
        'driver_user_id',
        'ride_id',
        'direction',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function cooperative(): BelongsTo
    {
        return $this->belongsTo(Cooperative::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_user_id');
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    /**
     * Saldo neto entre una cooperativa y un conductor puntual — positivo
     * significa que el conductor le debe ese monto a la cooperativa
     * (se quedó con efectivo que no le correspondía del todo), negativo
     * significa que la cooperativa le debe al conductor (se quedó con una
     * transferencia que no era toda suya). Exactamente el neteo que pidió
     * el usuario: "las que sean por transferencia... se descuentan de las
     * que le dieron en efectivo al conductor".
     */
    public static function balanceFor(int $cooperativeId, int $driverUserId): float
    {
        $query = self::query()
            ->where('cooperative_id', $cooperativeId)
            ->where('driver_user_id', $driverUserId);

        $driverOwes = (clone $query)->where('direction', 'driver_owes_cooperative')->sum('amount');
        $cooperativeOwes = (clone $query)->where('direction', 'cooperative_owes_driver')->sum('amount');

        return round((float) $driverOwes - (float) $cooperativeOwes, 2);
    }
}
