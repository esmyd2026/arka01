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
        // Pedido explícito del usuario: si el conductor completó lejos del
        // destino, el motivo que eligió también llega en este aviso — no
        // solo como texto en pantalla dentro de la carrera.
        $body = $this->ride->completion_reason
            ? "Su carrera se marcó como completada antes de llegar al destino. Motivo: {$this->ride->completion_reason}."
            : 'Su carrera se marcó como completada. Confirme el pago para cerrarla.';

        return (new WebPushMessage)
            ->title('Carrera completada')
            ->body($body)
            ->icon('/icons/icon.svg')
            ->data(['url' => "/carreras/{$this->ride->id}"])
            ->action('Ver', 'view');
    }
}
