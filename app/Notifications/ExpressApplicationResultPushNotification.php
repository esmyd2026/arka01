<?php

namespace App\Notifications;

use App\Models\ExpressApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class ExpressApplicationResultPushNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly ExpressApplication $application,
        private readonly bool $accepted,
    ) {}

    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $route = $this->application->route;
        $schedule = implode(', ', array_map(fn ($day) => ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'][$day], $route->days_of_week));

        return (new WebPushMessage)
            ->title($this->accepted ? '¡Expreso asignado!' : 'Postulación de Expreso no seleccionada')
            ->body($this->accepted
                ? "Quedó a cargo de {$route->name}: {$schedule} a las {$route->departure_time}. Revise su panel de Expresos."
                : "Otro conductor fue seleccionado para {$route->name}.")
            ->icon('/icons/icon.svg')
            ->data(['url' => route('express-routes.available')])
            ->action('Ver Expresos', 'view');
    }
}
