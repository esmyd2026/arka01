<?php

namespace App\Events;

use App\Models\RideRequest;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Un conductor aceptó la solicitud. Avisa al cliente que la pidió (para que
 * vea en vivo que ya tiene conductor) y al canal de la flota (para que, si se
 * había mandado a "toda la flota", el resto vea que ya se cerró — sección 3.5).
 */
class RideRequestAccepted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public RideRequest $rideRequest, public int $rideId) {}

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
        return 'ride-request.accepted';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'ride_request_id' => $this->rideRequest->id,
            'ride_id' => $this->rideId,
            'driver_name' => $this->rideRequest->acceptedBy->name,
        ];
    }
}
