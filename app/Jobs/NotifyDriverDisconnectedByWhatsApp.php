<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\WhatsAppFreeformSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pedido explícito del usuario: avisar por WhatsApp cuando un conductor se
 * desconecta (a propósito o porque se le cortó la ubicación), para animarlo
 * a volver. Encolado (no sincrónico) a propósito: se dispara desde un
 * request en vivo (el toggle "Activarme"/logout del propio conductor,
 * App\Http\Controllers\DriverLocationController y
 * App\Http\Controllers\Auth\AuthenticatedSessionController) o desde el
 * barrido de conductores stale (App\Console\Commands\SweepStaleDriverAvailability,
 * que puede procesar varios conductores en una sola corrida) — en ninguno de
 * los dos casos conviene que la respuesta HTTP (o el resto del barrido)
 * espere a que termine la llamada a la API de Meta.
 *
 * Se evaluó un trigger de MySQL para esto (el usuario lo pidió como opción)
 * y se descartó: un trigger de base de datos no puede hacer una llamada HTTP
 * a la API de WhatsApp — solo puede reaccionar dentro de la propia base de
 * datos. Un job de cola es la pieza correcta para "reaccionar a un cambio y
 * hacer una llamada externa sin bloquear al que lo disparó".
 */
class NotifyDriverDisconnectedByWhatsApp implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $driverUserId) {}

    public function handle(): void
    {
        $driver = User::find($this->driverUserId);

        if (! $driver) {
            return;
        }

        // Best-effort a propósito (pedido explícito del usuario: un
        // problema al mandar el WhatsApp nunca debe afectar nada más de la
        // plataforma) — cualquier error acá queda solo en el log, nunca
        // reintenta ni tira abajo el worker de la cola.
        try {
            WhatsAppFreeformSender::sendDisconnectedAlert($driver);
        } catch (Throwable $e) {
            Log::error('No se pudo procesar el aviso de desconexión por WhatsApp.', [
                'driver_user_id' => $this->driverUserId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
