<?php

namespace Tests\Feature\Chatbot;

use App\Jobs\ProcessChatbotMessage;
use App\Jobs\SendWhatsAppSessionRecoveryPrompt;
use App\Models\ChatbotConversation;
use App\Models\ChatbotSetting;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\WhatsAppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El chatbot de verdad respondiendo (pedido explícito del usuario): un
 * mensaje libre entrante ya no dispara la confirmación fija de siempre —
 * pasa por App\Services\Chatbot\ChatbotEngine. Estos tests corren el job
 * sincrónico (Bus::fake() solo se usa donde hace falta aislar), no solo
 * comprueban que se encoló algo.
 */
class ChatbotWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function enableWhatsApp(): void
    {
        Config::set('services.whatsapp.token', 'fake-token');
        Config::set('services.whatsapp.phone_number_id', '123456');
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.fake']]], 200)]);
    }

    private function sendInbound(string $fromDigits, string $text): void
    {
        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => ['messages' => [['from' => $fromDigits, 'text' => ['body' => $text], 'type' => 'text']]],
                ]],
            ]],
        ];

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();
    }

    public function test_a_greeting_from_a_registered_user_gets_a_real_menu_reply_not_the_old_fixed_text(): void
    {
        $this->enableWhatsApp();
        User::factory()->create(['phone' => '+593991234567']);

        $this->sendInbound('593991234567', 'Hola');

        Http::assertSent(function ($request) {
            $body = $request['text']['body'] ?? '';

            return str_contains($request->url(), 'graph.facebook.com')
                && str_contains($body, 'asistente virtual')
                && ! str_contains($body, 'Ya quedó conectado y activo');
        });

        $this->assertDatabaseHas('chatbot_conversations', ['phone' => '+593991234567', 'pending_intent' => 'AWAITING_MENU_CHOICE']);
    }

    public function test_an_unregistered_number_is_also_engaged_by_the_chatbot(): void
    {
        $this->enableWhatsApp();

        $this->sendInbound('593900000001', 'Quiero ser conductor');

        Http::assertSent(fn ($request) => str_contains($request['text']['body'] ?? '', 'Perfecto'));
        $this->assertDatabaseHas('chatbot_conversations', ['phone' => '+593900000001', 'user_id' => null]);
    }

    public function test_no_me_llego_el_codigo_triggers_the_real_resend_flow(): void
    {
        $this->enableWhatsApp();
        $user = User::factory()->create(['phone' => '+593991234567', 'phone_verified_at' => null]);

        $this->sendInbound('593991234567', 'no me llegó el código');

        // El mismo mecanismo que Auth\PhoneVerificationController::resend()
        // — no uno paralelo: un código nuevo quedó emitido de verdad.
        $this->assertNotNull($user->fresh()->phone_verification_code);
        Http::assertSent(fn ($request) => ($request['type'] ?? null) === 'template');
    }

    public function test_hablar_con_soporte_creates_a_support_ticket_with_context(): void
    {
        $this->enableWhatsApp();
        $user = User::factory()->create(['phone' => '+593991234567']);

        $this->sendInbound('593991234567', 'quiero hablar con soporte');

        $ticket = SupportTicket::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($ticket);
        $this->assertTrue($ticket->messages()->where('body', 'like', '%quiero hablar con soporte%')->exists());
        // Sin contacto de soporte configurado en /admin/chatbot (default de
        // la semilla: ambos en null), no debería mandar ninguna tarjeta de
        // contacto — solo el aviso de texto de siempre.
        Http::assertNotSent(fn ($request) => ($request['type'] ?? null) === 'contacts');
    }

    public function test_hablar_con_soporte_sends_a_whatsapp_contact_card_when_configured(): void
    {
        // Pedido explícito del usuario: "cuando mande a soporte que mande un
        // contacto, ese contacto que se actualice desde el panel admin" —
        // configurado acá mismo que como lo dejaría un admin desde
        // /admin/chatbot (Admin\ChatbotSettingController::update()).
        ChatbotSetting::current()->update([
            'support_contact_name' => 'Soporte Arka01',
            'support_contact_phone' => '+593991112222',
        ]);
        $this->enableWhatsApp();
        User::factory()->create(['phone' => '+593991234567']);

        $this->sendInbound('593991234567', 'quiero hablar con soporte');

        Http::assertSent(function ($request) {
            if (($request['type'] ?? null) !== 'contacts') {
                return false;
            }

            $contact = $request['contacts'][0] ?? [];

            return ($contact['name']['formatted_name'] ?? null) === 'Soporte Arka01'
                && ($contact['phones'][0]['phone'] ?? null) === '+593991112222';
        });
    }

    public function test_selecting_pedir_carrera_from_the_menu_starts_the_button_based_booking_flow(): void
    {
        // Pedido explícito del usuario: "que por allí se pueda pedir
        // también una carrera pero con botones" — el flujo con botones ya
        // existía (WhatsAppRideBookingHandler, ver
        // test_hablar_con_soporte_sends_a_whatsapp_contact_card_when_configured
        // arriba para el patrón de asserts), lo que faltaba era que se
        // pudiera arrancar tocando la opción del menú, no solo escribiendo
        // "pedir carrera" de memoria. Contexto de menú armado a mano (mismo
        // patrón que IntentDetectorTest) para no depender de en qué
        // posición exacta cae la intención en el menú real.
        $this->enableWhatsApp();
        WhatsAppSetting::current()->update(['client_ride_booking_enabled' => true]);
        $user = User::factory()->create(['phone' => '+593991234567', 'whatsapp_privacy_accepted_at' => now()]);
        ChatbotConversation::create([
            'phone' => '+593991234567',
            'user_id' => $user->id,
            'pending_intent' => 'AWAITING_MENU_CHOICE',
            'context' => ['menu_options' => ['PEDIR_CARRERA']],
        ]);

        $this->sendInbound('593991234567', '1');

        // Ya aceptó la privacidad antes, así que el flujo arranca
        // directo en "¿Para cuándo?" — con botones reales.
        Http::assertSent(function ($request) {
            if (($request['interactive']['type'] ?? null) !== 'button') {
                return false;
            }

            $buttonIds = collect($request['interactive']['action']['buttons'] ?? [])->pluck('reply.id');

            return $buttonIds->contains('wa_when_now');
        });
        $this->assertSame('WA_BOOKING_WHEN', ChatbotConversation::forPhone('+593991234567')->pending_intent);
    }

    public function test_gibberish_is_logged_as_unrecognized_and_gets_a_fallback_reply(): void
    {
        $this->enableWhatsApp();
        $user = User::factory()->create(['phone' => '+593991234567']);

        $this->sendInbound('593991234567', 'asdfg qwerty zzz');

        $this->assertDatabaseHas('chatbot_unrecognized_messages', [
            'phone' => '+593991234567',
            'user_id' => $user->id,
            'message' => 'asdfg qwerty zzz',
        ]);
        Http::assertSent(fn ($request) => str_contains($request['text']['body'] ?? '', 'no logré identificar'));
    }

    public function test_repeated_unrecognized_messages_escalate_to_a_support_offer(): void
    {
        $this->enableWhatsApp();
        Config::set('app.faker_locale', 'es_EC');
        $settings = ChatbotSetting::current();
        $settings->update(['max_fallback_attempts' => 2]);
        User::factory()->create(['phone' => '+593991234567']);

        $this->sendInbound('593991234567', 'asdfg primero');
        $this->sendInbound('593991234567', 'qwerty segundo');

        Http::assertSent(fn ($request) => str_contains($request['text']['body'] ?? '', 'atención más específica'));

        $conversation = ChatbotConversation::query()->where('phone', '+593991234567')->first();
        $this->assertSame(2, $conversation->unresolved_attempts);
        $this->assertSame('AWAITING_MENU_CHOICE', $conversation->pending_intent);
    }

    public function test_the_session_recovery_phrase_still_bypasses_the_chatbot_entirely(): void
    {
        // Regresión del flujo transaccional que NO se debía tocar (pedido
        // explícito del usuario): esta frase exacta sigue yendo por su
        // propio camino, nunca por el motor del chatbot.
        Bus::fake();
        User::factory()->create(['phone' => '+593991234567']);

        $this->sendInbound('593991234567', 'necesito recuperar mi sesion');

        Bus::assertDispatched(SendWhatsAppSessionRecoveryPrompt::class);
        Bus::assertNotDispatched(ProcessChatbotMessage::class);
    }

    public function test_a_faq_style_question_without_a_dedicated_intent_still_gets_answered(): void
    {
        $this->enableWhatsApp();
        User::factory()->create(['phone' => '+593991234567']);

        // "¿Cómo se calcula el precio?" no tiene una intención fija
        // dedicada — se resuelve por el rescate contra el catálogo de FAQ
        // real (sección 3 y 10 del pedido), no por una intención inventada.
        $this->sendInbound('593991234567', 'como se calcula el precio');

        Http::assertSent(fn ($request) => str_contains($request['text']['body'] ?? '', 'recargo si es horario nocturno'));
    }
}
