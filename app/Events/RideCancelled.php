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
 * El cliente canceló una carrera YA ACEPTADA (pedido explícito del usuario:
 * antes no existía ninguna forma de hacer esto — una vez aceptada, quedaba
 * sin salida hasta completarla). Mismo criterio que RideCompleted: el
 * conductor queda libre de nuevo en todas sus flotas, y se avisa por el
 * canal personal de ambas partes para que Ride/Index.vue y Ride/Show.vue se
 * actualicen en vivo sin depender de un canal de flota compartido.
 */
class RideCancelled implements ShouldBroadcast
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

        $channels[] = new PrivateChannel("App.Models.User.{$this->ride->client_user_id}");
        $channels[] = new PrivateChannel("App.Models.User.{$this->ride->driver_user_id}");

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'ride.cancelled';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'ride_id' => $this->ride->id,
            'driver_user_id' => $this->ride->driver_user_id,
            'is_available' => (bool) $this->ride->driver->driverProfile?->is_available,
        ];
    }
}
