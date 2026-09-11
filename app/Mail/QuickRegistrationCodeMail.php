<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Respaldo por correo del código de registro rápido (pedido explícito del
 * usuario: "priorizar el teléfono pero si no que sea por email") — se manda
 * SOLO cuando WhatsApp no está configurado o el envío falla de verdad (ver
 * QuickRegistrationController::sendCode()), nunca en paralelo.
 */
class QuickRegistrationCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $code,
    ) {}

    public function build(): self
    {
        return $this->subject('Su código para crear su cuenta en Arka01')
            ->markdown('emails.quick-registration-code');
    }
}
