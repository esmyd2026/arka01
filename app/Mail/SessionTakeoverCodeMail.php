<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Sesión única por cuenta (pedido explícito del usuario, caso real: no
 * sabía en qué navegador había quedado logueado y no lo dejaba entrar desde
 * otro) — respaldo por correo del código para cerrar la otra sesión, para
 * cuando no tiene la ventana de WhatsApp de 24h abierta (ver
 * WhatsAppFreeformSender::sendSessionTakeoverCode()).
 */
class SessionTakeoverCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $code,
        public string $lockUrl,
    ) {}

    public function build(): self
    {
        return $this->subject('Código para cerrar su otra sesión de Arka01')
            ->markdown('emails.session-takeover-code');
    }
}
