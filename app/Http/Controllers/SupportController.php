<?php

namespace App\Http\Controllers;

use App\Events\SupportMessageSent;
use App\Models\Faq;
use App\Models\SupportTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Centro de Ayuda / Soporte (sección 11 y 12 del roadmap de mejoras):
 * preguntas frecuentes según el rol de quien mira, más "Hablar con soporte"
 * — un ticket por usuario a la vez (App\Models\SupportTicket::openOrCreateFor()).
 */
class SupportController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $audience = $user->isDriver() ? 'conductor' : 'cliente';

        $faqs = Faq::query()
            ->forAudience($audience)
            ->where('is_active', true)
            ->orderBy('category')
            ->orderBy('sort_order')
            ->get(['id', 'category', 'question', 'answer']);

        // Mientras no tenga cerrado ningún ticket, se retoma el mismo — acá
        // solo se MUESTRA si ya existe, no se crea uno (eso pasa recién
        // cuando manda el primer mensaje, ver storeMessage()).
        $ticket = SupportTicket::query()
            ->where('user_id', $user->id)
            ->where('status', '!=', 'cerrado')
            ->latest()
            ->first();

        return Inertia::render('Support/Index', [
            'faqs' => $faqs,
            'isDriver' => $user->isDriver(),
            'ticket' => $ticket,
            'messages' => $ticket ? $ticket->messages()->with('sender')->oldest()->get() : [],
        ]);
    }

    public function storeMessage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:1000'],
        ]);

        $user = $request->user();
        $ticket = SupportTicket::openOrCreateFor($user);

        // Pedido explícito del usuario: un ticket cerrado no sigue una
        // conversación vieja — "Hablar con soporte" de nuevo abre uno nuevo
        // (openOrCreateFor() ya resuelve esto), pero si por una condición de
        // carrera llegó a cerrarse justo ahora, no se agrega a ese.
        if ($ticket->isClosed()) {
            throw ValidationException::withMessages([
                'body' => 'Ese ticket ya está cerrado.',
            ]);
        }

        $message = $ticket->messages()->create([
            'sender_user_id' => $user->id,
            'body' => $validated['body'],
        ]);
        $message->setRelation('sender', $user);

        // El cliente/conductor le escribió de nuevo — si soporte lo había
        // dejado "esperando usuario" o "resuelto", vuelve a quedar visible
        // como pendiente de atención.
        if (in_array($ticket->status, ['esperando_usuario', 'resuelto'], true)) {
            $ticket->update(['status' => 'nuevo']);
        }

        broadcast(new SupportMessageSent($message))->toOthers();

        return response()->json([
            'id' => $message->id,
            'support_ticket_id' => $message->support_ticket_id,
            'sender_user_id' => $message->sender_user_id,
            'sender_name' => $user->name,
            'sender_is_admin' => false,
            'body' => $message->body,
            'created_at' => $message->created_at->toIso8601String(),
            'ticket_id' => $ticket->id,
            'ticket_status' => $ticket->status,
        ]);
    }
}
