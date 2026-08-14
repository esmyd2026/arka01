<?php

namespace App\Notifications;

use App\Models\Ride;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Aviso push de que la carrera se canceló (pedido explícito del usuario) —
 * sobre todo importante si ya iba en camino, para que se entere aunque
 * tenga la app cerrada. Pedido explícito del usuario: ahora también puede
 * cancelar el conductor, no solo el cliente — el texto distingue quién fue,
 * mismo criterio que FleetInvitationPushNotification.
 */
class RideCancelledPushNotification extends Notification implements ShouldQueue
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
        $who = $this->ride->cancelled_by === 'driver' ? $this->ride->driver->name : 'El cliente';
        $reason = $this->ride->cancellation_reason;

        return (new WebPushMessage)
            ->title('Carrera cancelada')
            ->body($reason ? "{$who} canceló la carrera: {$reason}." : "{$who} canceló la carrera.")
            ->icon('/icons/icon.svg')
            ->data(['url' => '/carreras'])
            ->action('Ver', 'view');
    }
}
