<?php

namespace App\Notifications;

use App\Models\FleetInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class FleetInvitationRespondedPushNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly FleetInvitation $invitation,
        private readonly bool $accepted,
    ) {}

    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $responder = $this->invitation->initiatedByDriver()
            ? $this->invitation->fleet->owner->name
            : $this->invitation->driver->name;
        $result = $this->accepted ? 'aceptó' : 'rechazó';
        $url = $this->invitation->initiatedByDriver()
            ? '/mis-clientes'
            : "/flota/{$this->invitation->fleet_id}";

        return (new WebPushMessage)
            ->title($this->accepted ? 'Solicitud de flota aceptada' : 'Solicitud de flota rechazada')
            ->body("{$responder} {$result} su solicitud de flota.")
            ->icon('/icons/icon.svg')
            ->data(['url' => $url])
            ->action('Ver', 'view');
    }
}
