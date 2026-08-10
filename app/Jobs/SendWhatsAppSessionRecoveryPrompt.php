<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\WhatsAppFreeformSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pedido explícito del usuario: cuando alguien bloqueado por sesión única le
 * escribe primero al WhatsApp oficial (WhatsAppWebhookController::receive(),
 * frase exacta de Utils/whatsapp.js::buildSessionRecoveryWhatsAppUrl()), el
 * "bot" le confirma que ya puede volver a la web y pedir el código — mismo
 * patrón que SendWhatsAppWindowConfirmation, encolado para no demorar la
 * respuesta 200 al webhook de Meta.
 */
class SendWhatsAppSessionRecoveryPrompt implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $userId) {}

    public function handle(): void
    {
        $user = User::find($this->userId);

        if (! $user) {
            return;
        }

        try {
            WhatsAppFreeformSender::sendSessionRecoveryPrompt($user);
        } catch (Throwable $e) {
            Log::error('No se pudo mandar el aviso de recuperación de sesión por WhatsApp.', [
                'user_id' => $this->userId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
