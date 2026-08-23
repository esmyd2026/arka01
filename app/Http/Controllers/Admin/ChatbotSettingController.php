<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatbotSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administración → Chatbot → Mensajes generales (pedido explícito del
 * usuario): bienvenida, ayuda, fallback y despedida — fila única, mismo
 * patrón que Admin\WhatsAppSettingController.
 */
class ChatbotSettingController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('Admin/Chatbot/Settings', [
            'settings' => ChatbotSetting::current(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'welcome_message' => ['required', 'string', 'max:1000'],
            'help_message' => ['required', 'string', 'max:1000'],
            'fallback_message' => ['required', 'string', 'max:1000'],
            'fallback_escalation_message' => ['required', 'string', 'max:1000'],
            'farewell_message' => ['required', 'string', 'max:1000'],
            'max_fallback_attempts' => ['required', 'integer', 'min:1', 'max:5'],
            // Contacto de soporte (pedido explícito del usuario): los dos son
            // opcionales, pero si se completa uno, el otro pasa a ser
            // obligatorio — un nombre sin teléfono (o al revés) no sirve para
            // armar la tarjeta de contacto de WhatsApp.
            'support_contact_name' => ['nullable', 'required_with:support_contact_phone', 'string', 'max:100'],
            'support_contact_phone' => ['nullable', 'required_with:support_contact_name', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
        ], [
            'support_contact_phone.regex' => 'El teléfono va en formato internacional, con "+" y el código de país. Ej: +593991234567.',
        ]);

        ChatbotSetting::current()->update($validated + ['updated_by' => $request->user()->id]);

        return back()->with('status', 'Mensajes del chatbot actualizados.');
    }
}
