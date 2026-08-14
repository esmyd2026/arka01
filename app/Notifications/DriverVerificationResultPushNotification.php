<?php

namespace App\Notifications;

use App\Models\DriverProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class DriverVerificationResultPushNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly DriverProfile $profile,
        private readonly bool $approved,
    ) {}

    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $reason = $this->profile->verification_rejection_reason
            ? " Motivo: {$this->profile->verification_rejection_reason}"
            : '';

        return (new WebPushMessage)
            ->title($this->approved ? 'Perfil de conductor verificado' : 'Revise su verificación')
            ->body($this->approved
                ? 'Sus documentos fueron aprobados y su perfil ya figura como verificado.'
                : "No pudimos aprobar sus documentos.{$reason}")
            ->icon('/icons/icon.svg')
            ->data(['url' => route('driver.profile.edit')])
            ->action('Ver perfil', 'view');
    }
}
