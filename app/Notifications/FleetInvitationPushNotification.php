<?php

namespace App\Notifications;

use App\Models\FleetInvitation;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Aviso push de "te invitaron a una flota" (sección 3.2 y 9.5), mismo
 * criterio que RideRequestedPushNotification: avisa aunque el conductor
 * tenga la app cerrada, el WebSocket (Reverb) solo cubre la pestaña abierta.
 */
class FleetInvitationPushNotification extends Notification
{
    public function __construct(private readonly FleetInvitation $invitation) {}

    /**
     * @return array<int, string>
     */
    public function via($notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush($notifiable, $notification): WebPushMessage
    {
        $ownerName = $this->invitation->fleet->owner->name;

        return (new WebPushMessage)
            ->title('Nueva invitación a una flota')
            ->body("{$ownerName} te invitó a su flota de confianza.")
            ->icon('/icons/icon.svg')
            ->data(['url' => '/mis-clientes'])
            ->action('Ver', 'view');
    }
}
