<?php

namespace App\Notifications;

use App\Models\RideRequest;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Despacho secuencial estilo Uber (pedido explícito del usuario): se le
 * ofreció la carrera a cada candidato de la bolsa, uno a la vez, y ninguno
 * respondió a tiempo — avisa al cliente para que suba la oferta o cambie de
 * bolsa (mi flota / público / ambos) en vez de quedarse esperando en silencio.
 */
class RideRequestExpiredPushNotification extends Notification
{
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
            ->title('Nadie respondió a su solicitud')
            ->body('Ningún conductor disponible aceptó a tiempo. Pruebe de nuevo o suba su oferta.')
            ->icon('/icons/icon.svg')
            ->data(['url' => '/carreras'])
            ->action('Ver', 'view');
    }
}
