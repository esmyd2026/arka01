<?php

namespace App\Notifications;

use App\Models\Ride;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Bug reportado por el usuario: una carrera programada cuya hora ya pasó
 * quedaba mostrando "Iniciar viaje" sin ningún aviso — este es el push al
 * conductor, aunque tenga la app cerrada. Ver
 * App\Console\Commands\SendOverdueScheduledRideAlerts. No cancela nada solo
 * porque se venció (puede estar en camino, con tráfico) — solo avisa.
 */
class RideOverdueSchedulePushNotification extends Notification implements ShouldQueue
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
        $clientName = $this->ride->client->name;
        $time = $this->ride->rideRequest->scheduled_at->format('H:i');

        return (new WebPushMessage)
            ->title('Su carrera programada ya debería haber empezado')
            ->body("Tenía una carrera con {$clientName} a las {$time}. Inícela ahora, o avísele si va a demorar.")
            ->icon('/icons/icon.svg')
            ->data(['url' => "/carreras/{$this->ride->id}"])
            ->action('Ver', 'view');
    }
}
