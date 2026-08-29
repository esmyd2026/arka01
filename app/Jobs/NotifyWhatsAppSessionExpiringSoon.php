<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\WhatsAppFreeformSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pedido explícito del usuario: avisar por WhatsApp cuando a un conductor se
 * le está por cerrar la ventana de 24 horas, con un botón para que la
 * reabra escribiendo. Encolado (no sincrónico) por el mismo motivo que
 * App\Jobs\NotifyDriverDisconnectedByWhatsApp: lo dispara un comando
 * periódico (App\Console\Commands\NotifyExpiringWhatsAppSessions) que puede
 * procesar varios conductores en una sola corrida, y no conviene que esa
 * corrida espere a que termine cada llamada a la API de Meta.
 */
class NotifyWhatsAppSessionExpiringSoon implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $driverUserId) {}

    public function handle(): void
    {
        $driver = User::find($this->driverUserId);

        if (! $driver) {
            return;
        }

        // Best-effort a propósito (mismo criterio que NotifyDriverDisconnectedByWhatsApp):
        // un problema al mandar el WhatsApp nunca debe afectar nada más de la
        // plataforma.
        try {
            WhatsAppFreeformSender::sendSessionExpiringSoonNotice($driver);
        } catch (Throwable $e) {
            Log::error('No se pudo procesar el aviso de sesión de WhatsApp por vencer.', [
                'driver_user_id' => $this->driverUserId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
