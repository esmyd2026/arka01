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
 * El conductor arrancó una carrera que venía de una solicitud PROGRAMADA
 * (consideración agregada al alcance) — hasta este momento estaba en
 * 'scheduled' y no contaba como "en carrera" en ningún lado. Mismo criterio
 * de fan-out que RideCompleted: se avisa a todas las flotas donde el
 * conductor es miembro activo (ahora sí queda "ocupado" ahí), además del
 * canal personal de las dos partes de ESTA carrera puntual, para que
 * Ride/Show.vue se refresque solo si lo tenían abierto desde antes.
 */
class RideStarted implements ShouldBroadcast
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
        return 'ride.started';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'ride_id' => $this->ride->id,
            'driver_user_id' => $this->ride->driver_user_id,
        ];
    }
}
