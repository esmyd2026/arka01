<?php

namespace App\Jobs;

use App\Services\WhatsAppFreeformSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bug reportado por el usuario (caso real visto en el webhook: una cuenta
 * distinta trató de conectar un número que ya estaba verificado a nombre de
 * otro conductor) — avisa a quien acaba de escribir que ese número ya está
 * en uso, sin revelar nada del dueño real (privacidad).
 */
class SendWhatsAppNumberAlreadyRegisteredNotice implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $toE164) {}

    public function handle(): void
    {
        try {
            WhatsAppFreeformSender::sendNumberAlreadyRegisteredNotice($this->toE164);
        } catch (Throwable $e) {
            Log::error('No se pudo mandar el aviso de número ya registrado de WhatsApp.', [
                'to' => $this->toE164,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
