<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Respaldo de la contrapropuesta para cuando el cliente tiene la aplicación
 * cerrada, en segundo plano o temporalmente desconectada de Reverb.
 */
class RideRequestCounteredPushNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $rideRequestId,
        private readonly string $driverName,
        private readonly float $offeredAmount,
    ) {}

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
            ->title('Nueva contrapropuesta')
            ->body("{$this->driverName} propone \$".number_format($this->offeredAmount, 2).' por su carrera. Revísela para aceptar o rechazar.')
            ->icon('/icons/icon.svg')
            ->data([
                'url' => '/carreras',
                'category' => 'counter_offer',
                'ride_request_id' => $this->rideRequestId,
            ])
            ->action('Revisar', 'view');
    }
}
