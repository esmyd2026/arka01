<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\WhatsAppFreeformSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pedido explícito del usuario: cuando un conductor le escribe al número
 * oficial y se abre su ventana de 24h (WhatsAppWebhookController::receive()),
 * el "bot" le responde confirmando que ya está conectado — sirve como
 * comprobante de que el WhatsApp quedó bien enlazado para recibir avisos.
 * Encolado (no sincrónico) para que el webhook le siga respondiendo rápido a
 * Meta sin esperar a la llamada de salida.
 */
class SendWhatsAppWindowConfirmation implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $driverUserId) {}

    public function handle(): void
    {
        $driver = User::find($this->driverUserId);

        if (! $driver) {
            return;
        }

        try {
            WhatsAppFreeformSender::sendWindowConfirmation($driver);
        } catch (Throwable $e) {
            Log::error('No se pudo mandar la confirmación de ventana de WhatsApp.', [
                'driver_user_id' => $this->driverUserId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
