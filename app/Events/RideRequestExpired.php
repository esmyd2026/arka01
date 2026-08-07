<?php

namespace App\Events;

use App\Models\RideRequest;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Despacho secuencial estilo Uber (pedido explícito del usuario): se le
 * ofreció a cada candidato de la bolsa, uno a la vez, y ninguno respondió a
 * tiempo — avisa al CLIENTE en vivo (además del push) para que la tarjeta de
 * "Esperando respuesta" no se quede pegada sin explicación.
 */
class RideRequestExpired implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public RideRequest $rideRequest) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("App.Models.User.{$this->rideRequest->client_user_id}")];
    }

    public function broadcastAs(): string
    {
        return 'ride-request.expired';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'ride_request_id' => $this->rideRequest->id,
        ];
    }
}
