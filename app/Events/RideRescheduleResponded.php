<?php

namespace App\Events;

use App\Models\FleetMember;
use App\Models\Ride;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * El conductor respondió a un cambio de horario propuesto por el cliente
 * (confirmado o rechazado) — mismo criterio de fan-out que
 * RideRescheduleProposed del otro lado.
 */
class RideRescheduleResponded implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Ride $ride, public bool $confirmed) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $fleetIds = FleetMember::query()
            ->where('driver_user_id', $this->ride->driver_user_id)
            ->whereNull('left_at')
            ->pluck('fleet_id');

        $channels = $fleetIds
            ->map(fn ($fleetId) => new PrivateChannel("fleet.{$fleetId}"))
            ->all();

        $channels[] = new PrivateChannel("App.Models.User.{$this->ride->client_user_id}");
        $channels[] = new PrivateChannel("App.Models.User.{$this->ride->driver_user_id}");

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'ride.reschedule-responded';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'ride_id' => $this->ride->id,
            'confirmed' => $this->confirmed,
        ];
    }
}
