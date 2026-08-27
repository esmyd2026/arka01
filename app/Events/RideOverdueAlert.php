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
 * Bug reportado por el usuario: una carrera PROGRAMADA cuya hora ya pasó se
 * quedaba mostrando "Iniciar viaje" para siempre, sin ningún aviso de que
 * está vencida — nada la detectaba. Mismo criterio de fan-out que
 * RideReminderDue (el aviso simétrico de ANTES de la hora): en vivo si el
 * conductor tiene la app abierta, más el push de
 * App\Notifications\RideOverdueSchedulePushNotification para cuando no la
 * tiene. Ver App\Console\Commands\SendOverdueScheduledRideAlerts.
 */
class RideOverdueAlert implements ShouldBroadcast
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
        return 'ride.overdue-alert';
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
