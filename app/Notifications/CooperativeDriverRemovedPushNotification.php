<?php

namespace App\Notifications;

use App\Models\Cooperative;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Pedido explícito del usuario ("¿y qué pasa cuando un conductor es sacado
 * de la cooperativa? ¿se actualiza eso para el conductor?"): el acceso ya
 * se actualiza solo (DriverAccessResolver resuelve la membresía en vivo),
 * pero antes de esto el conductor no se enteraba de ningún modo — se
 * quedaba sin carreras nuevas de la cooperativa sin explicación. Ver
 * CooperativeDriverController::remove().
 */
class CooperativeDriverRemovedPushNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Cooperative $cooperative) {}

    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('Ya no pertenece a la cooperativa')
            ->body("{$this->cooperative->name} lo retiró de su equipo. Dejará de recibir sus carreras — su historial y billetera con ella se conservan.")
            ->icon('/icons/icon.svg')
            ->data(['url' => '/driver/profile'])
            ->action('Ver mi perfil', 'view');
    }
}
