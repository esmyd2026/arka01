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
 * Recordatorio de 15-20 min antes de una carrera programada (pedido
 * explícito del usuario) — además del push (llega aunque tenga la app
 * cerrada), esto avisa en vivo si el conductor tiene la app abierta en ese
 * momento (Dashboard.vue o Ride/Index.vue), mismo criterio de fan-out que
 * RideStarted/RideCancelled. Solo al conductor: es su recordatorio, no del
 * cliente.
 */
class RideReminderDue implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Ride $ride) {}

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

        $channels[] = new PrivateChannel("App.Models.User.{$this->ride->driver_user_id}");

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'ride.reminder-due';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'ride_id' => $this->ride->id,
            'client_name' => $this->ride->client->name,
        ];
    }
}
