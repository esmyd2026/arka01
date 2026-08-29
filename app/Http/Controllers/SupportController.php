<?php

namespace App\Http\Controllers;

use App\Services\Support\SupportCenterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Centro de Ayuda / Soporte (sección 11 y 12 del roadmap de mejoras):
 * preguntas frecuentes según el rol de quien mira, más "Hablar con soporte"
 * — un ticket por usuario a la vez (App\Models\SupportTicket::openOrCreateFor()).
 *
 * Lógica real en App\Services\Support\SupportCenterService (roadmap app
 * móvil, "full backend").
 */
class SupportController extends Controller
{
    public function __construct(private readonly SupportCenterService $supportCenter) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $ticket = $this->supportCenter->openTicketFor($user);

        return Inertia::render('Support/Index', [
            'faqs' => $this->supportCenter->faqsFor($user),
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

        $message = $this->supportCenter->postMessage($request->user(), $validated['body']);

        return response()->json([
            'id' => $message->id,
            'support_ticket_id' => $message->support_ticket_id,
            'sender_user_id' => $message->sender_user_id,
            'sender_name' => $request->user()->name,
            'sender_is_admin' => false,
            'body' => $message->body,
            'created_at' => $message->created_at->toIso8601String(),
            'ticket_id' => $message->ticket->id,
            'ticket_status' => $message->ticket->status,
        ]);
    }
}
