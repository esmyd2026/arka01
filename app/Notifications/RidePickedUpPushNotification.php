<?php

namespace App\Notifications;

use App\Models\Ride;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Aviso push de "su viaje comenzó" (pedido explícito del usuario, roadmap de
 * mejoras: "▶️ Tu viaje ha comenzado") — antes `pickedUp()` solo transmitía
 * por WebSocket (RidePickedUp), sin push; se agrega para que el cliente se
 * entere aunque tenga la app cerrada, mismo patrón que las demás
 * notificaciones de esta carrera (RideArrivedPushNotification, etc.).
 */
class RidePickedUpPushNotification extends Notification
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
            ->title('▶️ Su viaje comenzó')
            ->body('Ya está en camino hacia su destino.')
            ->icon('/icons/icon.svg')
            ->data(['url' => "/carreras/{$this->ride->id}"])
            ->action('Ver', 'view');
    }
}
