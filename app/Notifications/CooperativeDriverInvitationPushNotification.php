<?php

namespace App\Notifications;

use App\Models\CooperativeDriverMembership;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class CooperativeDriverInvitationPushNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly CooperativeDriverMembership $membership) {}

    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('Invitación de cooperativa')
            ->body("{$this->membership->cooperative->name} quiere vincularte como conductor afiliado.")
            ->icon('/icons/icon.svg')
            ->data(['url' => '/cooperativas/invitaciones'])
            ->action('Revisar', 'view');
    }
}
