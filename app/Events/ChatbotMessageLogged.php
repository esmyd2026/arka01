<?php

namespace App\Events;

use App\Models\ChatbotMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Un mensaje nuevo (entrante o saliente) se registró en la transcripción
 * completa de WhatsApp (pedido explícito del usuario: "tener a todos los
 * que me escriben y poder responder desde allí") — se dispara una sola vez,
 * desde el mismo punto que loguea el mensaje (WhatsAppWebhookController
 * para entrantes, WhatsAppFreeformSender::logOutbound() para salientes), y
 * llega a DOS lugares: el canal `admins` (para refrescar la lista de
 * conversaciones del inbox sin tenerla abierta) y el canal de esta
 * conversación puntual (para la vista de chat abierta, si hay una).
 */
class ChatbotMessageLogged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ChatbotMessage $message,
        public int $conversationId,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admins'),
            new PrivateChannel("whatsapp-conversation.{$this->conversationId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'whatsapp.message.logged';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'conversation_id' => $this->conversationId,
            'phone' => $this->message->phone,
            'direction' => $this->message->direction,
            'body' => $this->message->body,
            'created_at' => $this->message->created_at->toIso8601String(),
        ];
    }
}
