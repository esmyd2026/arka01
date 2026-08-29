<?php

namespace App\Notifications;

use App\Models\TrustCircleConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/** Avisa al destinatario incluso si Arka01 está en segundo plano. */
class TrustCircleRequestPushNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly TrustCircleConnection $circleConnection) {}

    public function via($notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush($notifiable, $notification): WebPushMessage
    {
        $requester = $this->circleConnection->requester;

        return (new WebPushMessage)
            ->title('Nueva solicitud para tu círculo')
            ->body("{$requester->full_name} quiere conectar contigo en su círculo de confianza.")
            ->icon('/icons/icon.svg')
            ->data(['url' => '/circulo-de-confianza'])
            ->action('Revisar solicitud', 'view');
    }
}
