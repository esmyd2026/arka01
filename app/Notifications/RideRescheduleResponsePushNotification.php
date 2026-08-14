<?php

namespace App\Notifications;

use App\Models\Ride;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Aviso push al cliente de que el conductor confirmó o rechazó el cambio de
 * horario que propuso (pedido explícito del usuario).
 */
class RideRescheduleResponsePushNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Ride $ride, private readonly bool $confirmed) {}

    /**
     * @return array<int, string>
     */
    public function via($notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush($notifiable, $notification): WebPushMessage
    {
        if ($this->confirmed) {
            return (new WebPushMessage)
                ->title('Cambio de horario confirmado')
                ->body("{$this->ride->driver->name} confirmó el nuevo horario de su carrera.")
                ->icon('/icons/icon.svg')
                ->data(['url' => "/carreras/{$this->ride->id}"])
                ->action('Ver', 'view');
        }

        return (new WebPushMessage)
            ->title('El conductor no puede a esa hora')
            ->body("{$this->ride->driver->name} no puede a la nueva hora — la carrera sigue en su horario original.")
            ->icon('/icons/icon.svg')
            ->data(['url' => "/carreras/{$this->ride->id}"])
            ->action('Ver', 'view');
    }
}
