<?php

namespace App\Services\Chatbot\IntentActionHandlers;

use App\Events\SupportTicketEscalated;
use App\Models\ChatbotSetting;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\WhatsAppFreeformSender;

/**
 * "Hablar con soporte" (pedido explícito del usuario, sección 13): reutiliza
 * App\Models\SupportTicket::openOrCreateFor() — el mismo mecanismo que ya
 * usa Admin\SupportTicketController y el "Hablar con soporte" de la app, no
 * un sistema de tickets paralelo. Solo aplica a cuentas registradas: la
 * tabla `support_tickets` exige `user_id`, no admite un ticket sin dueño.
 */
class EscalateToSupportHandler
{
    public function handle(?User $user, string $rawText, string $phoneE164): string
    {
        if (! $user) {
            return 'Para hablar con soporte hace falta tener una cuenta creada en Arka01 — ¿quieres que te ayude a crear una? Si ya tienes cuenta, ingresa a la app y escríbenos desde el número que declaraste ahí.';
        }

        $ticket = SupportTicket::openOrCreateFor($user);

        // Pedido explícito del usuario ("ayudame a ver la trazabilidad en
        // el panel administrativo"): la alerta global es solo al CREAR el
        // ticket — si ya tenía uno abierto y sigue escribiendo, esos
        // mensajes siguientes van por SupportMessageSent (por ticket
        // puntual), no por acá de nuevo. `wasRecentlyCreated` lo pone
        // Eloquent solo, no hace falta guardarlo a mano.
        if ($ticket->wasRecentlyCreated) {
            broadcast(new SupportTicketEscalated($ticket))->toOthers();
        }

        // Pedido explícito del usuario: "enviar al administrador el
        // contexto necesario... para que el usuario no tenga que explicar
        // de nuevo todo el problema" — se prefija el mensaje para que quede
        // claro en el panel admin que este primer mensaje lo generó el
        // asistente, no que lo escribió la persona tal cual.
        $ticket->messages()->create([
            'sender_user_id' => $user->id,
            'body' => "📋 Resumen automático del asistente de WhatsApp: \"{$rawText}\"",
        ]);

        // Contacto humano de soporte (pedido explícito del usuario: "cuando
        // mande a soporte que mande un contacto, ese contacto que se
        // actualice desde el panel admin") — tarjeta de WhatsApp de verdad,
        // no solo un número en texto, para que la persona pueda escribirle o
        // llamarlo directo mientras espera respuesta en la app. Opcional: si
        // no está completado en /admin/chatbot, se manda igual el aviso de
        // texto de siempre, sin la tarjeta.
        $settings = ChatbotSetting::current();
        if ($settings->support_contact_name && $settings->support_contact_phone) {
            WhatsAppFreeformSender::sendContact($phoneE164, $settings->support_contact_name, $settings->support_contact_phone);
        }

        return 'Ya avisé a soporte con lo que me contaste — un admin va a responderte desde la sección de Soporte dentro de la app. ¿Necesitas algo más por acá mientras tanto?';
    }
}
