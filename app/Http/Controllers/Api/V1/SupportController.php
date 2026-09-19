<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Support\SupportCenterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Centro de Ayuda / Soporte desde la app móvil (roadmap app móvil, "full
 * backend"). Reusa App\Services\Support\SupportCenterService, la misma
 * lógica que la web. Alcance deliberado: el móvil consulta los mensajes por
 * sondeo (mismo patrón que el resto de la app móvil), no por WebSocket
 * todavía — el broadcast sigue existiendo para que la pantalla web de
 * soporte lo reciba en vivo mientras tanto.
 */
class SupportController extends Controller
{
    public function __construct(private readonly SupportCenterService $supportCenter) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $ticket = $this->supportCenter->openTicketFor($user);

        return response()->json([
            'faqs' => $this->supportCenter->faqsFor($user),
            'ticket' => $ticket ? ['id' => $ticket->id, 'status' => $ticket->status] : null,
            'messages' => $ticket ? $this->formatMessages($ticket->messages()->with('sender')->oldest()->get()) : [],
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
            'ticket_id' => $message->ticket->id,
            'ticket_status' => $message->ticket->status,
            'sender_user_id' => $message->sender_user_id,
            'sender_name' => $request->user()->name,
            'sender_is_admin' => false,
            'body' => $message->body,
            'created_at' => $message->created_at->toIso8601String(),
        ], 201);
    }

    private function formatMessages($messages): array
    {
        return $messages->map(fn ($message) => [
            'id' => $message->id,
            'sender_user_id' => $message->sender_user_id,
            'sender_name' => $message->sender->name,
            'sender_is_admin' => (bool) $message->sender->is_admin,
            'body' => $message->body,
            'created_at' => $message->created_at->toIso8601String(),
        ])->values()->all();
    }
}
