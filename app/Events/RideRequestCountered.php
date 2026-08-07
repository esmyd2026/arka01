<?php

namespace App\Events;

use App\Models\RideRequest;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * El conductor contraofertó un precio distinto al sugerido (sección 5, su
 * única ronda permitida). Avisa al cliente (para que responda) y al canal de
 * la flota (para que, si era "toda la flota", el resto vea que ya no está
 * disponible — la tomó este conductor).
 */
class RideRequestCountered implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public RideRequest $rideRequest) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("App.Models.User.{$this->rideRequest->client_user_id}"),
            new PrivateChannel("fleet.{$this->rideRequest->fleet_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ride-request.countered';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'ride_request_id' => $this->rideRequest->id,
            'offered_amount' => $this->rideRequest->current_offered_price,
            'driver_name' => $this->rideRequest->negotiatingDriver->name,
        ];
    }
}
