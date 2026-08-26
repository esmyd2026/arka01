<?php

namespace Tests\Feature\Admin;

use App\Events\SupportMessageSent;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\WhatsAppSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Administración → Soporte (roadmap de mejoras, sección 12).
 */
class SupportTicketTest extends TestCase
{
    use RefreshDatabase;

    private function enableWhatsApp(): void
    {
        Config::set('services.whatsapp.token', 'fake-token');
        Config::set('services.whatsapp.phone_number_id', '123456');
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.fake']]], 200)]);
    }

    public function test_a_regular_user_cannot_access_the_admin_panel(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.support-tickets.index'))->assertForbidden();
    }

    public function test_tickets_can_be_filtered_by_status(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        SupportTicket::factory()->create(['status' => 'nuevo']);
        SupportTicket::factory()->create(['status' => 'cerrado']);

        $response = $this->actingAs($admin)->get(route('admin.support-tickets.index', ['status' => 'nuevo']));

        $response->assertInertia(fn ($page) => $page->has('tickets.data', 1));
    }

    public function test_an_admin_can_reply_and_the_ticket_moves_to_waiting_for_user(): void
    {
        Event::fake([SupportMessageSent::class]);
        $admin = User::factory()->create(['is_admin' => true]);
        $ticket = SupportTicket::factory()->create(['status' => 'nuevo']);

        $response = $this->actingAs($admin)->postJson(route('admin.support-tickets.reply', $ticket), [
            'body' => '¡Hola! ¿En qué le podemos ayudar?',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('support_ticket_messages', ['sender_user_id' => $admin->id, 'body' => '¡Hola! ¿En qué le podemos ayudar?']);
        $this->assertSame('esperando_usuario', $ticket->fresh()->status);
        Event::assertDispatched(SupportMessageSent::class);
    }

    /**
     * Pedido explícito del usuario ("ayudame a ver que se cumpla el tema de
     * tomar control humana"): antes esta respuesta se quedaba solo en la
     * base de datos, nunca le llegaba de verdad al cliente por WhatsApp.
     */
    public function test_reply_sends_a_real_whatsapp_message_when_the_client_has_an_active_session(): void
    {
        $this->enableWhatsApp();
        $admin = User::factory()->create(['is_admin' => true]);
        $client = User::factory()->create(['phone' => '+593991234567']);
        WhatsAppSession::query()->create(['user_id' => $client->id, 'opened_at' => now(), 'expires_at' => now()->addHours(20)]);
        $ticket = SupportTicket::factory()->for($client)->create(['status' => 'nuevo']);

        $response = $this->actingAs($admin)->postJson(route('admin.support-tickets.reply', $ticket), [
            'body' => 'Ya estamos revisando su caso.',
        ]);

        $response->assertOk()->assertJson(['whatsapp_sent' => true]);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com')
            && ($request['text']['body'] ?? null) === 'Ya estamos revisando su caso.');
    }

    public function test_reply_reports_when_whatsapp_could_not_be_sent(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        // Sin sesión de WhatsApp abierta — el cliente nunca escribió, o la
        // ventana de 24h ya se cerró.
        $client = User::factory()->create(['phone' => '+593991234567']);
        $ticket = SupportTicket::factory()->for($client)->create(['status' => 'nuevo']);

        $response = $this->actingAs($admin)->postJson(route('admin.support-tickets.reply', $ticket), [
            'body' => 'Ya estamos revisando su caso.',
        ]);

        $response->assertOk()->assertJson(['whatsapp_sent' => false]);
    }

    public function test_an_admin_can_change_the_ticket_status(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $ticket = SupportTicket::factory()->create(['status' => 'nuevo']);

        $this->actingAs($admin)
            ->patch(route('admin.support-tickets.update-status', $ticket), ['status' => 'resuelto'])
            ->assertRedirect();

        $this->assertSame('resuelto', $ticket->fresh()->status);
    }
}
