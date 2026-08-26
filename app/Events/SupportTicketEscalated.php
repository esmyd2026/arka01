<?php

namespace App\Events;

use App\Models\SupportTicket;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Un cliente pidió hablar con un asesor humano y se le abrió un ticket
 * NUEVO (pedido explícito del usuario: "ayudame a ver la trazabilidad en
 * el panel administrativo... como tenemos en los bot que hemos
 * desarrollado mejor") — se dispara UNA sola vez, al crear el ticket
 * (ver EscalateToSupportHandler), no en cada mensaje siguiente de uno ya
 * abierto: para eso ya existe SupportMessageSent, por ticket puntual.
 * Este va al canal `admins` (routes/channels.php), para que cualquier
 * admin conectado se entere aunque no tenga el ticket abierto.
 */
class SupportTicketEscalated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public SupportTicket $ticket) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('admins')];
    }

    public function broadcastAs(): string
    {
        return 'support.ticket.escalated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'ticket_id' => $this->ticket->id,
            'user_name' => $this->ticket->user->name,
        ];
    }
}
