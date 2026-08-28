<?php

namespace App\Notifications;

use App\Models\RideRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Aviso push de "te llegó una solicitud de carrera" (sección 3.5 y 9.5), para
 * que el conductor se entere aunque tenga la app cerrada o en segundo plano
 * — el WebSocket (Reverb) solo avisa si la pestaña sigue abierta.
 */
class RideRequestedPushNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly RideRequest $rideRequest) {}

    /**
     * @return array<int, string>
     */
    public function via($notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush($notifiable, $notification): WebPushMessage
    {
        $clientName = $this->rideRequest->client->name;
        $price = $this->rideRequest->current_offered_price;

        // Pedido explícito del usuario: si viene PROGRAMADA, el aviso tiene
        // que decirlo — si no, el conductor entiende que es "para ahora" y
        // puede confundirse (o preocuparse de más) sin la fecha/hora real.
        $body = $this->rideRequest->is_scheduled
            ? "{$clientName} programó una carrera para el {$this->rideRequest->scheduled_at->format('d/m')} a las {$this->rideRequest->scheduled_at->format('H:i')} por \${$price}."
            : "{$clientName} te pidió una carrera por \${$price}.";

        return (new WebPushMessage)
            ->title($this->rideRequest->is_scheduled ? 'Carrera programada nueva' : 'Nueva solicitud de carrera')
            ->body($body)
            ->icon('/icons/icon.svg')
            ->data([
                'url' => '/carreras',
                'category' => 'incoming_ride',
                'ride_request_id' => $this->rideRequest->id,
            ])
            ->action('Ver', 'view');
    }
}
