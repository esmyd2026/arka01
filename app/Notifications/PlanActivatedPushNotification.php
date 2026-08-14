<?php

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Aviso push de que su plan ya quedó activo (reporte del usuario: "activé
 * un plan a un cliente y no le llegó la notificación" — no fallaba, esta
 * notificación directamente no existía todavía). Cubre las tres formas de
 * activar un plan a la vez porque las tres pasan por
 * App\Services\SubscriptionActivator::activate(), único lugar donde se
 * dispara — activación manual del admin, aprobación de un comprobante, y
 * el auto-activado de un plan/promo en $0.
 */
class PlanActivatedPushNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Subscription $subscription) {}

    /**
     * @return array<int, string>
     */
    public function via($notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush($notifiable, $notification): WebPushMessage
    {
        $plan = $this->subscription->plan;
        $routeName = $plan->owner_type === 'driver' ? 'driver.plan.edit' : 'client.plan.edit';

        return (new WebPushMessage)
            ->title('¡Su plan ya está activo!')
            ->body("Su plan {$plan->name} quedó activado.")
            ->icon('/icons/icon.svg')
            ->data(['url' => route($routeName)])
            ->action('Ver mi plan', 'view');
    }
}
