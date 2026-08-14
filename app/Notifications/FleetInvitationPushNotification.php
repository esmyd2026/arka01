<?php

namespace App\Notifications;

use App\Models\FleetInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Aviso push de "te invitaron a una flota" o "un conductor quiere unirse a
 * tu flota" (sección 3.2 y 9.5, segunda dirección pedida explícitamente por
 * el usuario) — mismo criterio que RideRequestedPushNotification: avisa
 * aunque quien lo reciba tenga la app cerrada, el WebSocket (Reverb) solo
 * cubre la pestaña abierta.
 */
class FleetInvitationPushNotification extends Notification implements ShouldQueue
{
    use Queueable;

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
        if ($this->invitation->initiatedByDriver()) {
            return (new WebPushMessage)
                ->title('Un conductor quiere unirse a su flota')
                ->body("{$this->invitation->driver->name} le mandó una solicitud para unirse a su flota de confianza.")
                ->icon('/icons/icon.svg')
                ->data(['url' => "/flota/{$this->invitation->fleet_id}"])
                ->action('Ver', 'view');
        }

        $ownerName = $this->invitation->fleet->owner->name;

        return (new WebPushMessage)
            ->title('Nueva invitación a una flota')
            ->body("{$ownerName} te invitó a su flota de confianza.")
            ->icon('/icons/icon.svg')
            ->data(['url' => '/mis-clientes'])
            ->action('Ver', 'view');
    }
}
