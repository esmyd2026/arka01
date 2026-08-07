<?php

namespace App\Events;

use App\Models\RideRequest;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Bug reportado por el usuario: un cliente le pidió la carrera a un
 * conductor puntual (no "toda la flota"), ese conductor la rechazó, y el
 * cliente se quedaba sin ningún aviso — la tarjeta "Esperando respuesta"
 * seguía mostrando "buscando entre tus conductores disponibles" para
 * siempre. `RideRequestCancelled` no sirve para esto: para una solicitud
 * dirigida, avisa al canal del CONDUCTOR (tiene sentido cuando es el
 * CLIENTE el que cancela, para sacarla de la lista del conductor), pero acá
 * es al revés — el conductor ya sabe que la rechazó, quien necesita
 * enterarse es el cliente.
 */
class RideRequestDeclined implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public RideRequest $rideRequest, public string $driverName) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("App.Models.User.{$this->rideRequest->client_user_id}")];
    }

    public function broadcastAs(): string
    {
        return 'ride-request.declined';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'ride_request_id' => $this->rideRequest->id,
            'driver_name' => $this->driverName,
        ];
    }
}
