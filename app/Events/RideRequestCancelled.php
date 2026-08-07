<?php

namespace App\Events;

use App\Models\RideRequest;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * El cliente canceló una solicitud que todavía estaba pendiente, para que el
 * o los conductores que la estaban viendo la saquen de su lista en vivo.
 */
class RideRequestCancelled implements ShouldBroadcast
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
        return 'ride-request.cancelled';
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
