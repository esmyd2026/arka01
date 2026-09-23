<?php

namespace App\Notifications;

use App\Models\FleetInvitation;
use App\Notifications\Channels\FcmChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Pedido explícito del usuario: si el conductor apagó la aprobación manual
 * de invitaciones (DriverProfile::requires_fleet_invitation_approval), un
 * cliente que lo agrega a su flota queda vinculado de una — sin este aviso,
 * el conductor no se enteraría nunca de que tiene un cliente nuevo. A
 * diferencia de FleetInvitationPushNotification (que pide una respuesta),
 * esto es solo informativo: no hay nada que aceptar o rechazar.
 */
class FleetInvitationAutoAcceptedPushNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly FleetInvitation $invitation) {}

    /**
     * @return array<int, string>
     */
    public function via($notifiable): array
    {
        return [WebPushChannel::class, FcmChannel::class];
    }

    public function toWebPush($notifiable, $notification): WebPushMessage
    {
        $ownerName = $this->invitation->fleet->owner->name;

        return (new WebPushMessage)
            ->title('Nuevo cliente en su cartera')
            ->body("{$ownerName} lo agregó a su flota — ya es su cliente. Revise su detalle.")
            ->icon('/icons/icon.svg')
            ->data(['url' => '/mis-clientes'])
            ->action('Ver', 'view');
    }
}
