<?php

namespace App\Events;

use App\Models\Ride;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Mantiene sincronizado el estado del cobro entre cliente y conductor.
 * La notificación informa incluso con la app cerrada; este evento actualiza
 * inmediatamente la carrera que cualquiera de los dos tenga abierta.
 */
class RidePaymentUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Ride $ride) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("ride.{$this->ride->id}")];
    }

    public function broadcastAs(): string
    {
        return 'ride.payment.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'ride_id' => $this->ride->id,
            'payment_status' => $this->ride->payment_status,
            'payment_method' => $this->ride->payment_method,
        ];
    }
}
