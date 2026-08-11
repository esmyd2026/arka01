<?php

namespace App\Services\Chatbot\IntentActionHandlers;

use App\Models\SupportTicket;
use App\Models\User;

/**
 * "Hablar con soporte" (pedido explícito del usuario, sección 13): reutiliza
 * App\Models\SupportTicket::openOrCreateFor() — el mismo mecanismo que ya
 * usa Admin\SupportTicketController y el "Hablar con soporte" de la app, no
 * un sistema de tickets paralelo. Solo aplica a cuentas registradas: la
 * tabla `support_tickets` exige `user_id`, no admite un ticket sin dueño.
 */
class EscalateToSupportHandler
{
    public function handle(?User $user, string $rawText): string
    {
        if (! $user) {
            return 'Para hablar con soporte hace falta tener una cuenta creada en Arka01 — ¿querés que te ayude a crear una? Si ya tenés cuenta, ingresá a la app y escribinos desde el número que declaraste ahí.';
        }

        $ticket = SupportTicket::openOrCreateFor($user);

        // Pedido explícito del usuario: "enviar al administrador el
        // contexto necesario... para que el usuario no tenga que explicar
        // de nuevo todo el problema" — se prefija el mensaje para que quede
        // claro en el panel admin que este primer mensaje lo generó el
        // asistente, no que lo escribió la persona tal cual.
        $ticket->messages()->create([
            'sender_user_id' => $user->id,
            'body' => "📋 Resumen automático del asistente de WhatsApp: \"{$rawText}\"",
        ]);

        return 'Ya avisé a soporte con lo que me contaste — un admin va a responderte desde la sección de Soporte dentro de la app. ¿Necesitás algo más por acá mientras tanto?';
    }
}
