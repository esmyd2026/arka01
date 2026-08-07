<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Sesión única por cuenta (consideración de seguridad agregada al alcance):
 * cuando se bloquea un login porque ya hay una sesión activa en otro
 * dispositivo, se le avisa al dueño real de la cuenta por correo — así se
 * entera si alguien más está probando entrar, no solo el que lo intentó.
 */
class ConcurrentLoginAttemptMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public ?string $ipAddress,
        public string $lockUrl,
    ) {}

    public function build(): self
    {
        return $this->subject('Alguien intentó entrar a su cuenta de Arka01')
            ->markdown('emails.concurrent-login-attempt');
    }
}
