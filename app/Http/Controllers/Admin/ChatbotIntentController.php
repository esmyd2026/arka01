<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatbotIntent;
use App\Models\ChatbotIntentKeyword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administración → Chatbot → Intenciones (pedido explícito del usuario):
 * catálogo de intenciones y sus vocablos asociados — App\Services\Chatbot\
 * IntentDetector es quien realmente los usa para clasificar un mensaje.
 * A propósito separado de /admin/integraciones/whatsapp (esa es la
 * configuración transaccional cruda — token, plantilla; esto es el
 * asistente virtual, nunca se mezclan).
 */
class ChatbotIntentController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Admin/Chatbot/Intents', [
            'intents' => ChatbotIntent::query()
                ->withCount('keywords')
                ->with('keywords')
                ->orderBy('sort_order')
                ->get(),
            // Pedido explícito del usuario (sección 15): venir desde
            // "Consultas no reconocidas" con el texto ya sugerido, para
            // convertirlo en intención nueva sin transcribirlo a mano.
            'seedPhrase' => $request->string('seed_phrase')->toString() ?: null,
        ]);
    }

    /**
     * Una intención nueva, puramente informativa (pedido explícito del
     * usuario, sección 15: convertir una consulta no reconocida en una
     * intención nueva) — nunca con `action`: eso son las 4 acciones seguras
     * ya construidas (reenviar código, escalar a soporte, etc., ver
     * App\Services\Chatbot\ChatbotEngine), un admin no puede inventar una
     * nueva desde acá, solo texto libre de respuesta.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:60', 'alpha_dash', 'uppercase', 'unique:chatbot_intents,code'],
            'label' => ['required', 'string', 'max:100'],
            'role_scope' => ['required', Rule::in(['cliente', 'conductor', 'ambos'])],
            'show_in_menu' => ['boolean'],
            'menu_label' => ['nullable', 'string', 'max:60'],
            'sort_order' => ['integer', 'min:0'],
            'reply_message' => ['required', 'string', 'max:2000'],
        ]);

        ChatbotIntent::query()->create($validated + ['is_active' => true, 'action' => null]);

        return back()->with('status', 'Intención creada.');
    }

    public function update(Request $request, ChatbotIntent $chatbotIntent): RedirectResponse
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'role_scope' => ['required', Rule::in(['cliente', 'conductor', 'ambos'])],
            'is_active' => ['boolean'],
            'show_in_menu' => ['boolean'],
            'menu_label' => ['nullable', 'string', 'max:60'],
            'sort_order' => ['integer', 'min:0'],
            'reply_message' => ['nullable', 'string', 'max:2000'],
        ]);

        $chatbotIntent->update($validated);

        return back()->with('status', 'Intención actualizada.');
    }

    /**
     * Un vocablo nuevo para una intención existente — el código/acción de
     * la intención no son editables acá (sección 11 del pedido: un admin
     * elige ENTRE acciones seguras ya construidas, nunca escribe lógica
     * nueva), solo su texto, vocablos y comportamiento de menú.
     */
    public function storeKeyword(Request $request, ChatbotIntent $chatbotIntent): RedirectResponse
    {
        $validated = $request->validate([
            'phrase' => ['required', 'string', 'max:120'],
            'weight' => ['integer', 'min:1', 'max:10'],
        ]);

        $chatbotIntent->keywords()->create($validated);

        return back()->with('status', 'Vocablo agregado.');
    }

    public function destroyKeyword(ChatbotIntentKeyword $chatbotIntentKeyword): RedirectResponse
    {
        $chatbotIntentKeyword->delete();

        return back()->with('status', 'Vocablo eliminado.');
    }
}
