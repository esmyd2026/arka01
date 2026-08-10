<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Correo de bienvenida (pedido explícito del usuario: "existe alguna
 * plantilla que se envía al registro... debe ser una plantilla con un buen
 * diseño") — no existía ninguno hasta ahora, ver RegisteredUserController::store().
 * Con diseño propio de la marca (oscuro + verde, mismo logo tipográfico que
 * la app — ver resources/js/Components/ApplicationLogo.vue), no el tema
 * genérico de Laravel que usan los otros 3 correos del proyecto. Se manda
 * sincrónico, no en cola — mismo criterio que los otros 3 correos ya
 * existentes (acá no hay ningún worker de cola corriendo en local, ver
 * Arka01_Progreso.md, así que ponerlo en cola lo dejaría sin mandar nunca en
 * desarrollo salvo que alguien arranque `queue:work` a mano).
 */
class WelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user) {}

    public function build(): self
    {
        return $this->subject('¡Bienvenido a Arka01, '.$this->user->name.'!')
            ->view('emails.welcome');
    }
}
