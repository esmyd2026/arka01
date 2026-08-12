<?php

namespace App\Events;

use App\Models\FleetInvitation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Un cliente invitó a un conductor a su flota, o un conductor le mandó una
 * solicitud a un cliente para unirse a la suya (sección 3.2, pedido
 * explícito del usuario para la segunda dirección). Antes no existía ningún
 * aviso en vivo para esto — el conductor tenía que refrescar la pantalla de
 * "Mis clientes de confianza" para verla; ahora el canal y el contenido
 * dependen de `initiated_by`, para que le llegue en vivo a quien de verdad
 * tiene que responder, sea cliente o conductor.
 */
class FleetInvitationCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public FleetInvitation $invitation) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->invitation->respondingPartyId())];
    }

    public function broadcastAs(): string
    {
        return 'fleet-invitation.created';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->invitation->id,
            'direction' => $this->invitation->initiated_by,
            'fleet_name' => $this->invitation->fleet->name,
            'owner_name' => $this->invitation->fleet->owner->name,
            'driver_name' => $this->invitation->driver->name,
            'message' => $this->invitation->message,
        ];
    }
}
