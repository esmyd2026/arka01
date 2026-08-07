<?php

namespace App\Events;

use App\Models\RideRequest;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * El cliente subió su propia oferta mientras la solicitud seguía pendiente
 * (sección 5 y feedback tipo "si nadie te acepta, subí la tarifa") — avisa a
 * los mismos canales que la solicitud original para que el precio nuevo se
 * vea sin recargar la página.
 */
class RideRequestPriceRaised implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public RideRequest $rideRequest) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        if ($this->rideRequest->isDirected()) {
            return [new PrivateChannel("App.Models.User.{$this->rideRequest->driver_user_id}")];
        }

        return [new PrivateChannel("fleet.{$this->rideRequest->fleet_id}")];
    }

    public function broadcastAs(): string
    {
        return 'ride-request.price-raised';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'ride_request_id' => $this->rideRequest->id,
            'offered_amount' => $this->rideRequest->current_offered_price,
        ];
    }
}
