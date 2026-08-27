<?php

namespace App\Notifications;

use App\Models\RideRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Bug reportado por el usuario: una solicitud PROGRAMADA que nadie aceptó
 * se quedaba en 'pending' para siempre, sin que el cliente se enterara —
 * distinta de RideRequestExpiredPushNotification (esa es del despacho
 * secuencial de 30 segundos, "suba su oferta" no tiene sentido acá: la hora
 * ya pasó, no hay oferta que subir). Ver
 * App\Console\Commands\ExpireOverdueScheduledRideRequests.
 */
class ScheduledRideRequestExpiredPushNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly RideRequest $rideRequest) {}

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
            ->title('Nadie tomó su carrera programada')
            ->body('Ya pasó la hora que había pedido y ningún conductor la aceptó. Pida una nueva cuando quiera.')
            ->icon('/icons/icon.svg')
            ->data(['url' => '/carreras'])
            ->action('Ver', 'view');
    }
}
