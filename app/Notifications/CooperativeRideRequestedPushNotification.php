<?php

namespace App\Notifications;

use App\Models\RideRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class CooperativeRideRequestedPushNotification extends Notification implements ShouldQueue
{
    use Queueable;
    public function __construct(private readonly RideRequest $rideRequest) {}
    public function via($notifiable): array { return [WebPushChannel::class]; }
    public function toWebPush($notifiable, $notification): WebPushMessage
    {
        return (new WebPushMessage)->title('Nueva solicitud para la cooperativa')
            ->body("{$this->rideRequest->client->name} espera asignación desde {$this->rideRequest->origin_address}.")
            ->icon('/icons/icon.svg')->data(['url' => '/cooperativa'])->action('Asignar', 'view');
    }
}
