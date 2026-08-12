<?php

namespace App\Notifications;

use App\Models\Ride;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Aviso push al conductor 15-20 min antes de una carrera programada (pedido
 * explícito del usuario) — para que no se le pase la hora de salir, aunque
 * tenga la app cerrada. Ver App\Console\Commands\SendUpcomingRideReminders.
 */
class RideScheduledReminderPushNotification extends Notification
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
        $clientName = $this->ride->client->name;
        $time = $this->ride->rideRequest->scheduled_at->format('H:i');

        return (new WebPushMessage)
            ->title('Su carrera programada está por empezar')
            ->body("Tiene una carrera con {$clientName} a las {$time}. Ya casi es hora de salir.")
            ->icon('/icons/icon.svg')
            ->data(['url' => "/carreras/{$this->ride->id}"])
            ->action('Ver', 'view');
    }
}
