<?php

namespace App\Notifications;

use App\Models\DriverProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
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
        // El rechazo se mantiene como aviso inmediato dentro de la app. La
        // aprobación también envía el correo de bienvenida solicitado, sin
        // duplicar correos cada vez que el administrador rechaza una corrección.
        return $this->approved
            ? [WebPushChannel::class, 'mail']
            : [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $reason = $this->profile->verification_rejection_reason
            ? " Motivo: {$this->profile->verification_rejection_reason}"
            : '';

        return (new WebPushMessage)
            ->title($this->approved ? '¡Bienvenido a la red Arka01!' : 'Revise su verificación')
            ->body($this->approved
                ? 'Tu perfil fue verificado. Ya puedes activarte, recibir carreras y empezar a construir tu base de clientes de confianza.'
                : "No pudimos aprobar sus documentos.{$reason}")
            ->icon('/icons/icon.svg')
            ->data(['url' => $this->approved ? route('dashboard') : route('driver.profile.edit')])
            ->action($this->approved ? 'Ir a mi panel' : 'Revisar perfil', 'view');
    }

    /**
     * Correo que se dispara únicamente cuando el administrador aprueba al
     * conductor. No lo deja disponible automáticamente: le comunica que ya
     * puede conectarse cuando esté listo para trabajar.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('¡Bienvenido a la red de confianza Arka01!')
            ->greeting("¡Hola, {$notifiable->name}!")
            ->line('Tu perfil de conductor fue verificado y ya está habilitado en Arka01.')
            ->line('Desde ahora puedes conectarte para recibir solicitudes de carrera y comenzar a construir tu propia base de clientes de confianza.')
            ->line('Cada viaje bien atendido puede convertirse en una relación de confianza: tus clientes podrán agregarte a sus flotas y volver a solicitarte directamente.')
            ->action('Ir a mi panel de conductor', route('dashboard'))
            ->line('Actívate cuando estés disponible. Solo suben los tuyos.');
    }
}
