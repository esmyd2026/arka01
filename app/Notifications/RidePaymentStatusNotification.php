<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class RidePaymentStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $rideId,
        private readonly string $title,
        private readonly string $message,
        private readonly string $url,
        private readonly string $type,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', WebPushChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type,
            'ride_id' => $this->rideId,
            'message' => $this->message,
            'url' => $this->url,
        ];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title)
            ->body($this->message)
            ->icon('/icons/icon.svg')
            ->data(['url' => $this->url])
            ->action('Revisar', 'view');
    }
}
