<?php

namespace App\Notifications;

use App\Models\RideRequest;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Bug reportado por el usuario: el cliente le pidió la carrera a un
 * conductor puntual, ese conductor la rechazó, y no había ningún aviso —
 * ni en vivo ni push. Recomienda ampliar la búsqueda en vez de dejarlo
 * adivinar qué pasó.
 */
class RideRequestDeclinedPushNotification extends Notification
{
    public function __construct(private readonly RideRequest $rideRequest, private readonly string $driverName) {}

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
            ->title('El conductor no aceptó su solicitud')
            ->body("{$this->driverName} rechazó su solicitud. Pruebe con toda su flota o el directorio público.")
            ->icon('/icons/icon.svg')
            ->data(['url' => '/carreras'])
            ->action('Ver', 'view');
    }
}
