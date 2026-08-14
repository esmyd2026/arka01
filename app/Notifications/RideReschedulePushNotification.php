<?php

namespace App\Notifications;

use App\Models\Ride;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Aviso push al conductor de que el cliente propuso otro horario para una
 * carrera programada (pedido explícito del usuario) — necesita confirmarlo
 * o rechazarlo, no queda aplicado solo.
 */
class RideReschedulePushNotification extends Notification implements ShouldQueue
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
        $newTime = $this->ride->pending_reschedule_at->format('d/m H:i');

        return (new WebPushMessage)
            ->title('El cliente propuso otro horario')
            ->body("{$this->ride->client->name} quiere cambiar la carrera para el {$newTime}. Confirmalo o rechazalo.")
            ->icon('/icons/icon.svg')
            ->data(['url' => "/carreras/{$this->ride->id}"])
            ->action('Ver', 'view');
    }
}
