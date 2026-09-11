<?php

namespace App\Http\Controllers\Auth;

use App\Events\SupportTicketEscalated;
use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Pedido explícito del usuario (caso real: "cuando pido el código no
 * llega... la experiencia del usuario es muy mala aquí"): si ni el código
 * por WhatsApp ni por correo funcionan, un último recurso desde la propia
 * pantalla de login — escribirle a soporte sin tener que entrar primero.
 * Reusa el mismo mecanismo de siempre (App\Models\SupportTicket, ver
 * SupportController y EscalateToSupportHandler del bot de WhatsApp), no un
 * sistema de tickets paralelo.
 *
 * `support_tickets` exige `user_id` (un ticket es siempre de una cuenta) —
 * mismo criterio que EscalateToSupportHandler: si no se puede identificar
 * ninguna cuenta con lo que escribió, no hay a quién asociarle el ticket, así
 * que se lo dice claro y se lo manda por otro canal en vez de fallar en
 * silencio.
 */
class LoginSupportController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'login' => ['required', 'string'],
            'message' => ['required', 'string', 'max:1000'],
        ]);

        $user = User::findByLoginIdentifier($validated['login']);

        if (! $user) {
            return response()->json([
                'ok' => false,
                'message' => 'No encontramos ninguna cuenta con ese dato — escríbanos por WhatsApp directo, ahí sí podemos ayudarlo sin necesitar la cuenta.',
            ]);
        }

        $ticket = SupportTicket::openOrCreateFor($user);

        // Mismo criterio que EscalateToSupportHandler (bot de WhatsApp): la
        // alerta global es solo al CREAR el ticket, para no duplicar avisos
        // si esta persona ya tenía uno abierto de antes.
        if ($ticket->wasRecentlyCreated) {
            broadcast(new SupportTicketEscalated($ticket))->toOthers();
        }

        $ticket->messages()->create([
            'sender_user_id' => $user->id,
            'body' => "📋 Mensaje enviado desde la pantalla de inicio de sesión (no pudo entrar): \"{$validated['message']}\"",
        ]);

        Log::info('Ticket de soporte abierto desde la pantalla de login.', ['user_id' => $user->id, 'ticket_id' => $ticket->id]);

        return response()->json([
            'ok' => true,
            'message' => 'Listo — le avisamos a soporte. Apenas pueda entrar, va a ver la respuesta en la sección de Soporte dentro de la app.',
        ]);
    }
}
