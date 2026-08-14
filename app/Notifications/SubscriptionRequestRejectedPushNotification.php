<?php

namespace App\Notifications;

use App\Models\SubscriptionRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class SubscriptionRequestRejectedPushNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly SubscriptionRequest $subscriptionRequest) {}

    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $request = $this->subscriptionRequest->loadMissing('plan');
        $routeName = $request->plan->isForDrivers() ? 'driver.plan.edit' : 'client.plan.edit';
        $reason = $request->admin_note ? " Motivo: {$request->admin_note}" : '';

        return (new WebPushMessage)
            ->title('Revise su solicitud de plan')
            ->body("No pudimos aprobar su solicitud del plan {$request->plan->name}.{$reason}")
            ->icon('/icons/icon.svg')
            ->data(['url' => route($routeName)])
            ->action('Revisar', 'view');
    }
}
