<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Aviso de demanda a un conductor cercano (pedido explícito del usuario: "que
 * hay demanda cerca y si son de su flota") — el admin lo dispara desde el
 * centro de operaciones para una zona puntual (Admin\OperationsController::notifyNearby()).
 * El mensaje se arma distinto según si el conductor es o no de la flota que
 * tiene la demanda, por eso viaja ya armado en vez de reconstruirse acá.
 */
class DemandAlertPushNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $sectorName, public readonly bool $fromOwnFleet) {}

    /**
     * @return array<int, string>
     */
    public function via($notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush($notifiable, $notification): WebPushMessage
    {
        $body = $this->fromOwnFleet
            ? "Hay clientes de su flota pidiendo carreras cerca, en {$this->sectorName}."
            : "Hay demanda cerca, en {$this->sectorName} (fuera de su flota, pero puede recibirla igual desde el directorio público).";

        return (new WebPushMessage)
            ->title('Demanda cerca de usted')
            ->body($body)
            ->icon('/icons/icon.svg')
            ->data(['url' => '/carreras'])
            ->action('Ver', 'view');
    }
}
