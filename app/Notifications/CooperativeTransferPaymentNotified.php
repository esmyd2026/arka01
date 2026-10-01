<?php

namespace App\Notifications;

use App\Models\Ride;
use App\Support\Currency;
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
            // Moneda DEL CLIENTE que hizo la transferencia (pedido
            // explícito del usuario: "arka01 debe funcionar en cualquier
            // país") — ver User::country().
            'message' => $ride->client->name.' informó una transferencia de '.Currency::format($total, $ride->client->country()).' por la carrera #'.$ride->id.'. Revise su cuenta bancaria.',
        ];
    }
}
