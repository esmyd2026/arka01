<?php

namespace App\Notifications;

use App\Models\Ride;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class CooperativeTransferPaymentNotified extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Ride $ride) {}

    public function via(object $notifiable): array
    {
        return ['database', WebPushChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        return $this->payload();
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('Transferencia informada')
            ->body($this->payload()['message'])
            ->icon('/icons/icon.svg')
            ->data(['url' => '/cooperativa'])
            ->action('Revisar carrera', 'view');
    }

    private function payload(): array
    {
        $ride = $this->ride->loadMissing('client');
        $total = (float) ($ride->settled_price ?? ((float) $ride->price + (float) ($ride->stops_price ?? 0)));

        return [
            'type' => 'cooperative_transfer_payment_notified',
            'ride_id' => $ride->id,
            'client_user_id' => $ride->client_user_id,
            'message' => $ride->client->name.' informó una transferencia de $'.number_format($total, 2).' por la carrera #'.$ride->id.'. Revise su cuenta bancaria.',
        ];
    }
}
