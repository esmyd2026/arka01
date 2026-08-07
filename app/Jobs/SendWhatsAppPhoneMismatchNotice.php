<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\WhatsAppFreeformSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pedido explícito del usuario: si el conductor le escribe al número oficial
 * desde un teléfono distinto al que declaró en su perfil, no se abre la
 * ventana en su nombre (los avisos siempre van al número del perfil) — se le
 * avisa por qué, al número que ACABA de escribir.
 */
class SendWhatsAppPhoneMismatchNotice implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $fromE164,
        public readonly int $intendedDriverUserId,
    ) {}

    public function handle(): void
    {
        $driver = User::find($this->intendedDriverUserId);

        if (! $driver) {
            return;
        }

        try {
            WhatsAppFreeformSender::sendPhoneMismatchNotice($this->fromE164, $driver);
        } catch (Throwable $e) {
            Log::error('No se pudo mandar el aviso de número distinto de WhatsApp.', [
                'driver_user_id' => $this->intendedDriverUserId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
