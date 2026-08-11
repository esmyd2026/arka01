<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Chatbot\ChatbotEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pedido explícito del usuario: el webhook de WhatsApp
 * (WhatsAppWebhookController::receive()) le sigue respondiendo rápido a
 * Meta sin esperar a que el chatbot piense — encolado, igual que el resto
 * de los avisos salientes de WhatsApp (ver App\Jobs\SendWhatsApp*).
 * `userId` nullable a propósito: el chatbot también atiende a números sin
 * cuenta todavía (prospectos).
 */
class ProcessChatbotMessage implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $phoneE164,
        public readonly string $text,
        public readonly ?int $userId,
    ) {}

    public function handle(ChatbotEngine $engine): void
    {
        $engine->respondTo($this->phoneE164, $this->userId ? User::find($this->userId) : null, $this->text);
    }
}
