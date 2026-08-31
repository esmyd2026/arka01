<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

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

    /**
     * Saldo neto de TODA la cooperativa de una sola vez (pedido explícito
     * del usuario: "cuánto le deben o cuánto ella le debe a su equipo" —
     * un total, no solo entrar conductor por conductor). Mismo signo que
     * balanceFor(): positivo = el equipo en conjunto le debe a la
     * cooperativa, negativo = la cooperativa le debe al equipo.
     */
    public static function totalBalance(int $cooperativeId): float
    {
        $query = self::query()->where('cooperative_id', $cooperativeId);
        $driverOwes = (clone $query)->where('direction', 'driver_owes_cooperative')->sum('amount');
        $cooperativeOwes = (clone $query)->where('direction', 'cooperative_owes_driver')->sum('amount');

        return round((float) $driverOwes - (float) $cooperativeOwes, 2);
    }

    /**
     * Saldo neto por conductor, para TODOS los conductores pedidos de una
     * sola vez (pedido explícito del usuario: la cooperativa necesita ver
     * de un vistazo quién le debe o a quién le debe, no solo un total
     * ciego) — dos consultas agregadas en vez de una por conductor.
     *
     * @return array<int, float> driver_user_id => balance
     */
    public static function balancesForDrivers(int $cooperativeId, Collection|array $driverUserIds): array
    {
        $driverUserIds = collect($driverUserIds)->unique()->values();
        if ($driverUserIds->isEmpty()) {
            return [];
        }

        $sums = self::query()
            ->where('cooperative_id', $cooperativeId)
            ->whereIn('driver_user_id', $driverUserIds)
            ->selectRaw('driver_user_id, direction, SUM(amount) as total')
            ->groupBy('driver_user_id', 'direction')
            ->get();

        return $driverUserIds->mapWithKeys(function (int $driverId) use ($sums) {
            $owed = (float) $sums->first(fn ($row) => (int) $row->driver_user_id === $driverId && $row->direction === 'driver_owes_cooperative')?->total;
            $owing = (float) $sums->first(fn ($row) => (int) $row->driver_user_id === $driverId && $row->direction === 'cooperative_owes_driver')?->total;

            return [$driverId => round($owed - $owing, 2)];
        })->all();
    }
}
