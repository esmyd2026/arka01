<?php

namespace App\Notifications;

use App\Models\Ride;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Aviso push de "su conductor lo está esperando" (pedido explícito del
 * usuario) — para que el cliente se entere aunque tenga la app cerrada,
 * mismo patrón que RideStartedPushNotification.
 */
class RideArrivedPushNotification extends Notification implements ShouldQueue
{
    use Queueable;

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
            ->title('Su conductor lo está esperando')
            ->body("{$driverName} llegó al punto de encuentro.")
            ->icon('/icons/icon.svg')
            ->data(['url' => "/carreras/{$this->ride->id}"])
            ->action('Ver', 'view');
    }
}
