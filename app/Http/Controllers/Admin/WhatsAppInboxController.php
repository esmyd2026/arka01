<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatbotConversation;
use App\Models\ChatbotMessage;
use App\Services\WhatsAppFreeformSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inbox de WhatsApp (pedido explícito del usuario: "tener a todos los que
 * me escriben y poder responder desde allí yo también o activar el bot o
 * no") — a diferencia de /admin/soporte (que solo lista tickets abiertos a
 * propósito por el usuario, "hablar con soporte"), acá aparece CUALQUIER
 * número que le haya escrito al WhatsApp oficial, tenga o no cuenta, haya o
 * no pedido soporte. Reusa ChatbotConversation (una fila por teléfono) y
 * ChatbotMessage (la transcripción completa, ver esas migraciones).
 */
class WhatsAppInboxController extends Controller
{
    public function index(Request $request): Response
    {
        // El orden tiene que reflejar la actividad real de CADA mensaje
        // (ChatbotMessage, que siempre se registra) y no
        // chatbot_conversations.last_message_at — esa columna deja de
        // actualizarse en cuanto una conversación queda con bot_paused=true
        // (ChatbotEngine::process() corta el pipeline antes de llegar a
        // tocarla), así que una conversación activa pero pausada se
        // quedaría hundida en la lista. Se ordena por MAX(id) y no por
        // MAX(created_at): la columna es un timestamp de precisión de
        // segundo (ver la migración), así que dos mensajes seguidos en el
        // mismo segundo (típico en un intercambio rápido) empatarían y el
        // orden quedaría indefinido — el id autoincremental no tiene ese
        // problema.
        $latest = DB::table('chatbot_messages')
            ->selectRaw('phone, MAX(id) as last_id')
            ->groupBy('phone');

        $conversations = ChatbotConversation::query()
            ->joinSub($latest, 'latest', fn ($join) => $join->on('latest.phone', '=', 'chatbot_conversations.phone'))
            ->with('user:id,name,phone')
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = $request->string('q');
                $query->where(fn ($query) => $query->where('chatbot_conversations.phone', 'like', "%{$term}%")
                    ->orWhereHas('user', fn ($query) => $query->where('name', 'like', "%{$term}%")));
            })
            ->select('chatbot_conversations.*', 'latest.last_id')
            ->orderByDesc('latest.last_id')
            ->paginate(30)
            ->withQueryString();

        $phones = collect($conversations->items())->pluck('phone');
        $lastMessages = ChatbotMessage::query()
            ->whereIn('phone', $phones)
            ->orderByDesc('id')
            ->get(['id', 'phone', 'body', 'direction', 'created_at'])
            ->unique('phone')
            ->keyBy('phone');

        $conversations->through(fn (ChatbotConversation $conversation) => [
            'id' => $conversation->id,
            'phone' => $conversation->phone,
            'user_name' => $conversation->user?->name,
            'bot_paused' => $conversation->bot_paused,
            'last_message_at' => $lastMessages->get($conversation->phone)?->created_at?->toIso8601String(),
            'last_message_preview' => Str::limit($lastMessages->get($conversation->phone)?->body ?? '', 60),
            'last_message_direction' => $lastMessages->get($conversation->phone)?->direction,
        ]);

        return Inertia::render('Admin/WhatsAppInbox/Index', [
            'conversations' => $conversations,
            'filters' => $request->only('q'),
        ]);
    }

    public function show(ChatbotConversation $conversation): Response
    {
        $conversation->load('user:id,name,phone');

        $messages = ChatbotMessage::query()
            ->where('phone', $conversation->phone)
            ->orderBy('created_at')
            ->get(['id', 'direction', 'body', 'meta', 'created_at']);

        return Inertia::render('Admin/WhatsAppInbox/Show', [
            'conversation' => [
                'id' => $conversation->id,
                'phone' => $conversation->phone,
                'user' => $conversation->user,
                'bot_paused' => $conversation->bot_paused,
            ],
            'messages' => $messages,
        ]);
    }

    /**
     * Responder de verdad por WhatsApp, a nombre del admin (pedido
     * explícito del usuario) — mismo primitivo que ya usa
     * Admin\SupportTicketController::reply(), pero acá no depende de que
     * exista un ticket. Solo funciona con la ventana de 24h abierta (regla
     * de Meta): para un número con cuenta, se chequea igual que en
     * cualquier otro lado (User::hasActiveWhatsAppSession()); para un
     * número sin cuenta (nunca se registró) no existe WhatsAppSession
     * posible — se calcula directo si escribió algo en las últimas 24h.
     */
    public function reply(Request $request, ChatbotConversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:1000'],
        ]);

        $windowOpen = $conversation->user
            ? $conversation->user->hasActiveWhatsAppSession()
            : ChatbotMessage::query()
                ->where('phone', $conversation->phone)
                ->where('direction', 'in')
                ->where('created_at', '>=', now()->subHours(24))
                ->exists();

        if (! $windowOpen) {
            return response()->json([
                'sent' => false,
                'reason' => 'La ventana de 24h con este número está cerrada — no puede recibir un mensaje libre hasta que vuelva a escribir.',
            ], 422);
        }

        $sent = WhatsAppFreeformSender::sendText($conversation->phone, $validated['body']);

        $message = ChatbotMessage::query()
            ->where('phone', $conversation->phone)
            ->where('direction', 'out')
            ->latest('id')
            ->first();

        return response()->json([
            'sent' => $sent,
            'message' => $message ? [
                'id' => $message->id,
                'direction' => 'out',
                'body' => $message->body,
                'created_at' => $message->created_at->toIso8601String(),
            ] : null,
        ]);
    }

    /**
     * "Activar el bot o no" (pedido explícito del usuario) — control manual
     * por conversación, ver el chequeo nuevo en ChatbotEngine::process().
     */
    public function toggleBot(Request $request, ChatbotConversation $conversation): RedirectResponse
    {
        $validated = $request->validate(['bot_paused' => ['required', 'boolean']]);

        $conversation->update(['bot_paused' => $validated['bot_paused']]);

        return back()->with('status', $validated['bot_paused'] ? 'Bot pausado — este número solo lo atiende un admin ahora.' : 'Bot reactivado para este número.');
    }
}
