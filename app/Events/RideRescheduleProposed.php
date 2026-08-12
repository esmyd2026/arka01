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
 * El cliente propuso otro horario para una carrera programada ya aceptada
 * (pedido explícito del usuario: poder editarla si se equivocó de fecha/
 * hora) — el conductor tiene que confirmarlo o rechazarlo, no queda
 * aplicado solo (ver RideController::proposeReschedule()). Mismo criterio
 * de fan-out que RideStarted/RideCancelled: canal de flota (para que
 * Ride/Show.vue lo capte) + canal personal de las dos partes (para que
 * Ride/Index.vue y Dashboard.vue refresquen "Programados" sin importar cuál
 * pantalla tengan abierta).
 */
class RideRescheduleProposed implements ShouldBroadcast
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
        return 'ride.reschedule-proposed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'ride_id' => $this->ride->id,
            'client_name' => $this->ride->client->name,
            'proposed_at' => $this->ride->pending_reschedule_at,
        ];
    }
}
