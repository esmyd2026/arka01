<?php

namespace App\Events;

use App\Models\RideMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Mensaje nuevo del chat de una carrera (sección 10 del roadmap de mejoras)
 * — solo se transmite por el canal privado de ESA carrera puntual (ver
 * routes/channels.php: `ride.{rideId}`), nunca por el de flota, para que
 * nadie más que las dos partes lo reciba.
 */
class RideMessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public RideMessage $message) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("ride.{$this->message->ride_id}")];
    }

    public function broadcastAs(): string
    {
        return 'ride.message.sent';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'ride_id' => $this->message->ride_id,
            'sender_user_id' => $this->message->sender_user_id,
            'sender_name' => $this->message->sender->name,
            'body' => $this->message->body,
            'created_at' => $this->message->created_at->toIso8601String(),
        ];
    }
}
