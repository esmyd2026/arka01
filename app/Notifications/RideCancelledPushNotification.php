<?php

namespace App\Notifications;

use App\Models\Ride;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Aviso push al conductor de que el cliente canceló la carrera (pedido
 * explícito del usuario) — sobre todo importante si ya iba en camino, para
 * que se entere aunque tenga la app cerrada, mismo criterio que las demás push.
 */
class RideCancelledPushNotification extends Notification
{
    public function __construct(private readonly Ride $ride) {}

    /**
     * @return array<int, string>
     */
    public function via($notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush($notifiable, $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('Carrera cancelada')
            ->body('El cliente canceló la carrera.')
            ->icon('/icons/icon.svg')
            ->data(['url' => '/carreras'])
            ->action('Ver', 'view');
    }
}
