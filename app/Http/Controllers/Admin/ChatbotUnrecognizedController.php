<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatbotUnrecognizedMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administración → Chatbot → Consultas no reconocidas (pedido explícito del
 * usuario, sección 15): lo que App\Services\Chatbot\ChatbotEngine no pudo
 * clasificar con suficiente confianza — para descubrir necesidades reales y
 * convertirlas en intención/vocablo/FAQ nueva.
 */
class ChatbotUnrecognizedController extends Controller
{
    public function index(Request $request): Response
    {
        $onlyPending = $request->boolean('pending', true);

        return Inertia::render('Admin/Chatbot/Unrecognized', [
            'messages' => ChatbotUnrecognizedMessage::query()
                ->with('user:id,name,email')
                ->when($onlyPending, fn ($q) => $q->whereNull('reviewed_at'))
                ->latest()
                ->paginate(20)
                ->withQueryString(),
            'onlyPending' => $onlyPending,
        ]);
    }

    public function markReviewed(ChatbotUnrecognizedMessage $chatbotUnrecognizedMessage): RedirectResponse
    {
        $chatbotUnrecognizedMessage->update(['reviewed_at' => now()]);

        return back()->with('status', 'Marcada como revisada.');
    }
}
