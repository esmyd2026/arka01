<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class AdminDriverLifecyclePushNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly User $driver,
        public readonly string $stage,
    ) {}

    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $registered = $this->stage === 'registered';

        return (new WebPushMessage)
            ->title($registered ? 'Nuevo conductor registrado' : 'Conductor listo para verificar')
            ->body($registered
                ? "{$this->driver->full_name} creó una cuenta como conductor."
                : "{$this->driver->full_name} completó sus datos y documentos. Revise su expediente.")
            ->icon('/icons/icon.svg')
            ->data(['url' => route('admin.driver-verifications.index')])
            ->action('Abrir verificaciones', 'view');
    }
}
