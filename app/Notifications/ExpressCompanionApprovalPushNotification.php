<?php

namespace App\Notifications;

use App\Models\ExpressRouteCompanion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class ExpressCompanionApprovalPushNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly ExpressRouteCompanion $companion,
        private readonly string $result,
    ) {}

    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $route = $this->companion->route;
        [$title, $body] = match ($this->result) {
            'review' => [
                'Revise un acompañante del Expreso',
                "{$this->companion->passenger->name} quiere sumarse a {$route->name}. Confirme si el recorrido y el cupo son posibles.",
            ],
            'accepted' => ['Ya puede compartir el Expreso', "El conductor confirmó su lugar en {$route->name}."],
            default => ['No se pudo sumar al Expreso', "El conductor no pudo confirmar su lugar en {$route->name}."],
        };

        return (new WebPushMessage)
            ->title($title)
            ->body($body)
            ->icon('/icons/icon.svg')
            ->data(['url' => route('express-routes.show', $route)])
            ->action('Ver Expreso', 'view');
    }
}
