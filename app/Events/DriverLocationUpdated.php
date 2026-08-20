<?php

namespace App\Events;

use App\Models\DriverProfile;
use App\Models\FleetMember;
use App\Models\Ride;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Un conductor actualizó su posición mientras está disponible (sección 9.3).
 * Se avisa a TODAS las flotas a las que pertenece activamente, no solo a una,
 * porque un conductor puede ser parte de varios clientes de confianza a la vez.
 */
class DriverLocationUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public DriverProfile $driverProfile) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $fleetIds = FleetMember::query()
            ->where('driver_user_id', $this->driverProfile->user_id)
            ->whereNull('left_at')
            ->pluck('fleet_id');

        $channels = $fleetIds
            ->map(fn ($fleetId) => new PrivateChannel("fleet.{$fleetId}"))
            ->all();

        // Una unidad de cooperativa puede atender a un cliente sin ser
        // miembro de su flota privada. El canal de la carrera garantiza que
        // ambos participantes reciban la posición durante el viaje.
        $activeRideIds = Ride::query()
            ->where('driver_user_id', $this->driverProfile->user_id)
            ->where('status', 'in_progress')
            ->pluck('id');

        foreach ($activeRideIds as $rideId) {
            $channels[] = new PrivateChannel("ride.{$rideId}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'driver.location.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'driver_user_id' => $this->driverProfile->user_id,
            'lat' => $this->driverProfile->current_lat,
            'lng' => $this->driverProfile->current_lng,
            'is_available' => $this->driverProfile->is_available,
        ];
    }
}
