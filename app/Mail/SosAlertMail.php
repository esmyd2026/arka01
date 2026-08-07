<?php

namespace App\Mail;

use App\Models\SosAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Alerta de emergencia (sección 8: botón SOS) a un contacto de confianza —
 * no es un usuario de la plataforma, por eso va por correo y no por push.
 */
class SosAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public SosAlert $alert,
        public string $triggeredByName,
        public ?string $trackingUrl,
    ) {}

    public function build(): self
    {
        return $this->subject("Alerta de seguridad de {$this->triggeredByName} — Arka01")
            ->markdown('emails.sos-alert');
    }
}
