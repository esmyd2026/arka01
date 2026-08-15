<?php

namespace App\Notifications;

use App\Models\CooperativeDriverMembership;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class CooperativeDriverResponsePushNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly CooperativeDriverMembership $membership,
        private readonly bool $accepted,
    ) {}

    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $result = $this->accepted ? 'aceptó' : 'rechazó';

        return (new WebPushMessage)
            ->title($this->accepted ? 'Conductor vinculado' : 'Invitación rechazada')
            ->body("{$this->membership->driver->name} {$result} la invitación de la cooperativa.")
            ->icon('/icons/icon.svg')
            ->data(['url' => '/cooperativa/conductores'])
            ->action('Ver', 'view');
    }
}
