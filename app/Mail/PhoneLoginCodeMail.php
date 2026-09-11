<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Respaldo por correo del código de login sin contraseña (pedido explícito
 * del usuario, caso real: "cuando pido el código no llega") — se manda SOLO
 * cuando WhatsApp no está configurado o el envío falla de verdad (ver
 * PhoneLoginController::sendCode()), nunca en paralelo.
 */
class PhoneLoginCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $code,
    ) {}

    public function build(): self
    {
        return $this->subject('Su código para iniciar sesión en Arka01')
            ->markdown('emails.phone-login-code');
    }
}
