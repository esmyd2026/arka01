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
 * Una carrera se completó — el conductor queda libre de nuevo (consideración
 * agregada al alcance: sin esto, el punto de estado de "Mi flota" y de
 * "¿A quién se la pedís?" se quedaba pegado en "en carrera" hasta que alguien
 * recargara la página, aunque el conductor ya estuviera disponible). Mismo
 * criterio que DriverLocationUpdated: se avisa a TODAS las flotas donde el
 * conductor es miembro activo, no solo a la de esta carrera puntual — estar
 * "en carrera" lo saca de disponible en todos lados, así que quedar libre
 * también tiene que reflejarse en todos lados.
 *
 * Además se avisa por el canal personal de las dos partes de ESTA carrera
 * puntual (consideración agregada al alcance: se reportó que "En curso" en
 * la pantalla de Carreras no se movía a "Historial" en vivo para quien no
 * completó la carrera) — así Ride/Index.vue puede refrescar esas dos listas
 * sin depender de que la otra parte esté en un canal de flota.
 */
class RideCompleted implements ShouldBroadcast
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
        $channels[] = new PrivateChannel("ride.{$this->ride->id}");

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'ride.completed';
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
