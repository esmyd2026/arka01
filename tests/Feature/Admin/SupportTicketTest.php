<?php

namespace Tests\Feature\Admin;

use App\Events\SupportMessageSent;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Administración → Soporte (roadmap de mejoras, sección 12).
 */
class SupportTicketTest extends TestCase
{
    use RefreshDatabase;

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
