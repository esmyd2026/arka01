<?php

namespace App\Events;

use App\Models\FleetInvitation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Un cliente invitó a un conductor a su flota (sección 3.2). Antes no existía
 * ningún aviso en vivo para esto — el conductor tenía que refrescar la
 * pantalla de "Mis clientes de confianza" para verla.
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
        return [new PrivateChannel("App.Models.User.{$this->invitation->driver_user_id}")];
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
            'fleet_name' => $this->invitation->fleet->name,
            'owner_name' => $this->invitation->fleet->owner->name,
            'message' => $this->invitation->message,
        ];
    }
}
