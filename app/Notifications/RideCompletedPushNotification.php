<?php

namespace App\Notifications;

use App\Models\Ride;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Aviso push de "la carrera se completó" (pedido explícito del usuario: que
 * todas las notificaciones estén habilitadas en cada acción) para quien NO
 * la marcó como completada — para que se entere y pueda cerrarla (marcar el
 * pago) aunque tenga la app cerrada, mismo criterio que las demás push.
 */
class RideCompletedPushNotification extends Notification implements ShouldQueue
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
        return (new WebPushMessage)
            ->title('Carrera completada')
            ->body('Su carrera se marcó como completada. Confirme el pago para cerrarla.')
            ->icon('/icons/icon.svg')
            ->data(['url' => "/carreras/{$this->ride->id}"])
            ->action('Ver', 'view');
    }
}
