<?php

namespace App\Notifications;

use App\Models\TrustCircleConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/** Confirma al solicitante si la conexión fue aceptada o rechazada. */
class TrustCircleResponsePushNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly TrustCircleConnection $circleConnection,
        public readonly bool $accepted,
    ) {}

    public function via($notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush($notifiable, $notification): WebPushMessage
    {
        $person = $this->circleConnection->addressee;

        return (new WebPushMessage)
            ->title($this->accepted ? 'Solicitud de confianza aceptada' : 'Solicitud de confianza respondida')
            ->body($this->accepted
                ? "{$person->full_name} ya forma parte de tu círculo de confianza."
                : "{$person->full_name} no aceptó la solicitud para conectar.")
            ->icon('/icons/icon.svg')
            ->data(['url' => '/circulo-de-confianza'])
            ->action('Ver círculo', 'view');
    }
}
