<?php

namespace Tests\Feature\Admin;

use App\Models\ChatbotConversation;
use App\Models\ChatbotMessage;
use App\Models\User;
use App\Models\WhatsAppSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Inbox de WhatsApp (pedido explícito del usuario: "tener a todos los que
 * me escriben y poder responder desde allí yo también o activar el bot o
 * no") — a diferencia de /admin/soporte, acá aparece cualquier número que
 * haya escrito, tenga o no cuenta, haya o no pedido soporte.
 */
class WhatsAppInboxTest extends TestCase
{
    use RefreshDatabase;

    private function enableWhatsApp(): void
    {
        Config::set('services.whatsapp.token', 'fake-token');
        Config::set('services.whatsapp.phone_number_id', '123456');
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.fake']]], 200)]);
    }

    public function test_a_regular_user_cannot_access_the_inbox(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.whatsapp-inbox.index'))->assertForbidden();
    }

    public function test_the_inbox_lists_any_number_that_has_written_in_even_without_an_account(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $conversation = ChatbotConversation::forPhone('+593900000001');
        ChatbotMessage::query()->create(['phone' => '+593900000001', 'user_id' => null, 'direction' => 'in', 'body' => 'Quiero ser conductor']);

        $response = $this->actingAs($admin)->get(route('admin.whatsapp-inbox.index'));

        $response->assertInertia(fn ($page) => $page
            ->has('conversations.data', 1)
            ->where('conversations.data.0.id', $conversation->id)
            ->where('conversations.data.0.phone', '+593900000001')
            ->where('conversations.data.0.last_message_preview', 'Quiero ser conductor')
        );
    }

    /**
     * Bug real que se hubiera dado si el orden dependiera de
     * chatbot_conversations.last_message_at: esa columna deja de
     * actualizarse en cuanto bot_paused queda en true, así que una
     * conversación activa pero pausada se hundiría en la lista.
     */
    public function test_ordering_reflects_the_real_last_message_even_when_the_bot_is_paused(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        ChatbotConversation::forPhone('+593900000001');
        ChatbotMessage::query()->create(['phone' => '+593900000001', 'direction' => 'in', 'body' => 'Hola']);

        // Se crea DESPUÉS del de arriba (id más alto = mensaje más
        // reciente) — pero con last_message_at manualmente atrasado, para
        // simular justo el caso que rompía el orden: una conversación
        // pausada cuya columna de la tabla quedó congelada, mientras
        // ChatbotMessage sigue recibiendo mensajes de verdad.
        $paused = ChatbotConversation::forPhone('+593900000002');
        $paused->update(['bot_paused' => true, 'last_message_at' => now()->subDays(3)]);
        ChatbotMessage::query()->create(['phone' => '+593900000002', 'direction' => 'in', 'body' => 'Sigo esperando']);

        $response = $this->actingAs($admin)->get(route('admin.whatsapp-inbox.index'));

        $response->assertInertia(fn ($page) => $page->where('conversations.data.0.id', $paused->id));
    }

    public function test_the_inbox_can_be_searched_by_phone_or_user_name(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $match = User::factory()->create(['name' => 'Ana López', 'phone' => '+593991234567']);
        ChatbotConversation::forPhone('+593991234567')->update(['user_id' => $match->id]);
        ChatbotMessage::query()->create(['phone' => '+593991234567', 'direction' => 'in', 'body' => 'Hola']);

        ChatbotConversation::forPhone('+593900000009');
        ChatbotMessage::query()->create(['phone' => '+593900000009', 'direction' => 'in', 'body' => 'Hola']);

        $response = $this->actingAs($admin)->get(route('admin.whatsapp-inbox.index', ['q' => 'López']));

        $response->assertInertia(fn ($page) => $page->has('conversations.data', 1)->where('conversations.data.0.phone', '+593991234567'));
    }

    public function test_show_returns_the_full_transcript(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $conversation = ChatbotConversation::forPhone('+593991234567');
        ChatbotMessage::query()->create(['phone' => '+593991234567', 'direction' => 'in', 'body' => 'Hola']);
        ChatbotMessage::query()->create(['phone' => '+593991234567', 'direction' => 'out', 'body' => '¡Hola! ¿Qué necesita?']);

        $response = $this->actingAs($admin)->get(route('admin.whatsapp-inbox.show', $conversation->id));

        $response->assertInertia(fn ($page) => $page
            ->component('Admin/WhatsAppInbox/Show')
            ->has('messages', 2)
        );
    }

    public function test_an_admin_can_reply_with_an_open_window(): void
    {
        $this->enableWhatsApp();
        $admin = User::factory()->create(['is_admin' => true]);
        $client = User::factory()->create(['phone' => '+593991234567']);
        WhatsAppSession::query()->create(['user_id' => $client->id, 'opened_at' => now(), 'expires_at' => now()->addHours(20)]);
        $conversation = ChatbotConversation::forPhone('+593991234567');
        $conversation->update(['user_id' => $client->id]);

        $response = $this->actingAs($admin)->postJson(route('admin.whatsapp-inbox.reply', $conversation->id), [
            'body' => 'Ya le ayudamos con eso.',
        ]);

        $response->assertOk()->assertJson(['sent' => true]);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com')
            && ($request['text']['body'] ?? null) === 'Ya le ayudamos con eso.');
        $this->assertDatabaseHas('chatbot_messages', ['phone' => '+593991234567', 'direction' => 'out', 'body' => 'Ya le ayudamos con eso.']);
    }

    /**
     * Un número SIN cuenta (nunca se registró) no puede tener
     * WhatsAppSession (esa tabla exige user_id) — la ventana se calcula
     * directo desde su último mensaje entrante.
     */
    public function test_an_admin_can_reply_to_a_number_without_an_account_if_it_wrote_recently(): void
    {
        $this->enableWhatsApp();
        $admin = User::factory()->create(['is_admin' => true]);
        $conversation = ChatbotConversation::forPhone('+593900000001');
        ChatbotMessage::query()->create(['phone' => '+593900000001', 'direction' => 'in', 'body' => 'Hola', 'created_at' => now()->subHours(2)]);

        $response = $this->actingAs($admin)->postJson(route('admin.whatsapp-inbox.reply', $conversation->id), [
            'body' => 'Hola, ¿en qué le ayudamos?',
        ]);

        $response->assertOk()->assertJson(['sent' => true]);
    }

    public function test_reply_is_rejected_without_an_open_window(): void
    {
        $this->enableWhatsApp();
        $admin = User::factory()->create(['is_admin' => true]);
        $client = User::factory()->create(['phone' => '+593991234567']);
        $conversation = ChatbotConversation::forPhone('+593991234567');
        $conversation->update(['user_id' => $client->id]);

        $response = $this->actingAs($admin)->postJson(route('admin.whatsapp-inbox.reply', $conversation->id), [
            'body' => 'Hola',
        ]);

        $response->assertStatus(422)->assertJson(['sent' => false]);
        Http::assertNothingSent();
    }

    /**
     * Pedido explícito del usuario ("activar el bot o no") — control manual
     * por conversación.
     */
    public function test_an_admin_can_pause_and_resume_the_bot_for_a_conversation(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $conversation = ChatbotConversation::forPhone('+593991234567');

        $this->actingAs($admin)->patch(route('admin.whatsapp-inbox.toggle-bot', $conversation->id), ['bot_paused' => true])->assertRedirect();
        $this->assertTrue($conversation->fresh()->bot_paused);

        $this->actingAs($admin)->patch(route('admin.whatsapp-inbox.toggle-bot', $conversation->id), ['bot_paused' => false])->assertRedirect();
        $this->assertFalse($conversation->fresh()->bot_paused);
    }
}
