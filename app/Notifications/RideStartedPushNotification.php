<?php

namespace App\Notifications;

use App\Models\Ride;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Aviso push de "tu conductor ya va en camino" para una carrera que venía
 * PROGRAMADA (consideración agregada al alcance): el aviso de que aceptaron
 * la solicitud pasa mucho antes, en el momento de programarla — este es el
 * que de verdad importa, cuando el conductor arranca a buscarlo.
 */
class RideStartedPushNotification extends Notification
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
        $driverName = $this->ride->driver->name;

        return (new WebPushMessage)
            ->title('¡Su conductor ya va en camino!')
            ->body("{$driverName} arrancó su carrera programada.")
            ->icon('/icons/icon.svg')
            ->data(['url' => "/carreras/{$this->ride->id}"])
            ->action('Ver', 'view');
    }
}
