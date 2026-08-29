<?php

namespace App\Services\Support;

use App\Events\SupportMessageSent;
use App\Models\Faq;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Centro de Ayuda / Soporte (secciones 11 y 12 del roadmap de mejoras) —
 * extraído de SupportController (roadmap app móvil, "full backend": nunca
 * duplicar una regla de negocio entre web y móvil).
 */
class SupportCenterService
{
    public function faqsFor(User $user): Collection
    {
        $audience = $user->isDriver() ? 'conductor' : 'cliente';

        return Faq::query()
            ->forAudience($audience)
            ->where('is_active', true)
            ->orderBy('category')
            ->orderBy('sort_order')
            ->get(['id', 'category', 'question', 'answer']);
    }

    /**
     * Mientras no tenga cerrado ningún ticket, se retoma el mismo — esto
     * solo MUESTRA si ya existe, no crea uno (eso pasa recién con el
     * primer mensaje, ver postMessage()).
     */
    public function openTicketFor(User $user): ?SupportTicket
    {
        return SupportTicket::query()
            ->where('user_id', $user->id)
            ->where('status', '!=', 'cerrado')
            ->latest()
            ->first();
    }

    public function postMessage(User $user, string $body): SupportTicketMessage
    {
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
            'body' => $body,
        ]);
        $message->setRelation('sender', $user);
        $message->setRelation('ticket', $ticket);

        // El cliente/conductor le escribió de nuevo — si soporte lo había
        // dejado "esperando usuario" o "resuelto", vuelve a quedar visible
        // como pendiente de atención.
        if (in_array($ticket->status, ['esperando_usuario', 'resuelto'], true)) {
            $ticket->update(['status' => 'nuevo']);
        }

        broadcast(new SupportMessageSent($message))->toOthers();

        return $message;
    }
}
