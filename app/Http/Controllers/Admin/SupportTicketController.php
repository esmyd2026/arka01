<?php

namespace App\Http\Controllers\Admin;

use App\Events\SupportMessageSent;
use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administración → Soporte (sección 12 del roadmap de mejoras): el admin ve
 * los tickets de "Hablar con soporte" y responde desde acá.
 */
class SupportTicketController extends Controller
{
    public function index(Request $request): Response
    {
        $tickets = SupportTicket::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->with(['user', 'messages' => fn ($q) => $q->latest()->limit(1)])
            ->withCount('messages')
            ->latest('updated_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Admin/Support/Index', [
            'tickets' => $tickets,
            'filters' => $request->only('status'),
        ]);
    }

    public function show(SupportTicket $supportTicket): Response
    {
        $supportTicket->load(['user', 'messages.sender']);

        return Inertia::render('Admin/Support/Show', [
            'ticket' => $supportTicket,
        ]);
    }

    public function reply(Request $request, SupportTicket $supportTicket): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:1000'],
        ]);

        $admin = $request->user();

        $message = $supportTicket->messages()->create([
            'sender_user_id' => $admin->id,
            'body' => $validated['body'],
        ]);
        $message->setRelation('sender', $admin);

        // El admin respondió — pedido explícito del usuario ("estados"):
        // queda "esperando usuario" salvo que ya lo hubiera marcado
        // resuelto/cerrado a mano en el mismo movimiento (updateStatus).
        if ($supportTicket->status === 'nuevo' || $supportTicket->status === 'en_atencion') {
            $supportTicket->update(['status' => 'esperando_usuario']);
        }

        broadcast(new SupportMessageSent($message))->toOthers();

        return response()->json([
            'id' => $message->id,
            'support_ticket_id' => $message->support_ticket_id,
            'sender_user_id' => $message->sender_user_id,
            'sender_name' => $admin->name,
            'sender_is_admin' => true,
            'body' => $message->body,
            'created_at' => $message->created_at->toIso8601String(),
        ]);
    }

    public function updateStatus(Request $request, SupportTicket $supportTicket): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['nuevo', 'en_atencion', 'esperando_usuario', 'resuelto', 'cerrado'])],
        ]);

        $supportTicket->update($validated);

        return back()->with('status', 'Ticket actualizado.');
    }
}
