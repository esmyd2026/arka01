<?php

namespace App\Events;

use App\Models\SupportTicketMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Mensaje nuevo en un ticket de soporte (sección 12 del roadmap de mejoras)
 * — canal propio del ticket (ver routes/channels.php), así el usuario y
 * cualquier admin lo ven en vivo sin recargar.
 */
class SupportMessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public SupportTicketMessage $message) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("support-ticket.{$this->message->support_ticket_id}")];
    }

    public function broadcastAs(): string
    {
        return 'support.message.sent';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'support_ticket_id' => $this->message->support_ticket_id,
            'sender_user_id' => $this->message->sender_user_id,
            'sender_name' => $this->message->sender->name,
            'sender_is_admin' => (bool) $this->message->sender->is_admin,
            'body' => $this->message->body,
            'created_at' => $this->message->created_at->toIso8601String(),
        ];
    }
}
