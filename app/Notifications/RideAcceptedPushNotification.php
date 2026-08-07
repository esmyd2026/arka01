<?php

namespace App\Notifications;

use App\Models\Ride;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Aviso push de "un conductor aceptó tu carrera, ya va en camino" (pedido
 * explícito del usuario), para que el cliente se entere aunque tenga la app
 * cerrada o en segundo plano — el WebSocket (Reverb) solo avisa si la
 * pestaña sigue abierta (mismo criterio que RideRequestedPushNotification).
 */
class RideAcceptedPushNotification extends Notification
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
            ->title('¡Ya tiene conductor!')
            ->body("{$driverName} aceptó su carrera y ya va en camino.")
            ->icon('/icons/icon.svg')
            ->data(['url' => "/carreras/{$this->ride->id}"])
            ->action('Ver', 'view');
    }
}
