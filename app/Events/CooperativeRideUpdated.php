<?php

namespace App\Events;

use App\Models\RideRequest;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CooperativeRideUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public RideRequest $rideRequest, public string $action = 'updated') {}

    public function broadcastOn(): array
    {
        $cooperativeUserId = $this->rideRequest->cooperative?->user_id;

        // El mismo despachador también procesa solicitudes de flotas
        // personales. En ese caso no existe cooperativa ni canal al cual
        // avisar; devolver vacío evita que una actualización normal termine
        // en error 500 intentando leer user_id de null.
        return $cooperativeUserId
            ? [new PrivateChannel("App.Models.User.{$cooperativeUserId}")]
            : [];
    }

    public function broadcastAs(): string
    {
        return 'cooperative-ride.updated';
    }

    public function broadcastWith(): array
    {
        return ['ride_request_id' => $this->rideRequest->id, 'action' => $this->action, 'status' => $this->rideRequest->status];
    }
}
