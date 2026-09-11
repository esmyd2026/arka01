<?php

namespace Tests\Feature\Auth;

use App\Events\SupportTicketEscalated;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Último recurso desde la pantalla de login (pedido explícito del usuario,
 * caso real: "cuando pido el código no llega... la experiencia del usuario
 * es muy mala aquí") — escribirle a soporte sin tener que entrar primero.
 * Reusa App\Models\SupportTicket tal cual, mismo criterio que
 * EscalateToSupportHandler (bot de WhatsApp).
 */
class LoginSupportTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unknown_login_gets_a_clear_decline_instead_of_a_silent_failure(): void
    {
        $response = $this->postJson(route('login-support.store'), [
            'login' => '+593999999999',
            'message' => 'No me llega el código.',
        ]);

        $response->assertOk()->assertJson(['ok' => false]);
        $this->assertDatabaseCount('support_tickets', 0);
    }

    public function test_a_known_account_gets_a_ticket_opened_with_the_message(): void
    {
        Event::fake([SupportTicketEscalated::class]);
        $user = User::factory()->create(['phone' => '+593991234567']);

        $response = $this->postJson(route('login-support.store'), [
            'login' => $user->phone,
            'message' => 'No me llega el código por ningún lado.',
        ]);

        $response->assertOk()->assertJson(['ok' => true]);

        $ticket = SupportTicket::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('nuevo', $ticket->status);
        $this->assertStringContainsString('No me llega el código por ningún lado.', $ticket->messages()->latest()->first()->body);

        Event::assertDispatched(SupportTicketEscalated::class, fn ($event) => $event->ticket->id === $ticket->id);
    }

    public function test_a_second_message_reuses_the_open_ticket_without_re_escalating(): void
    {
        Event::fake([SupportTicketEscalated::class]);
        $user = User::factory()->create(['phone' => '+593991234567']);
        $existingTicket = SupportTicket::openOrCreateFor($user);
        $existingTicket->messages()->create(['sender_user_id' => $user->id, 'body' => 'Primer mensaje.']);

        $this->postJson(route('login-support.store'), [
            'login' => $user->phone,
            'message' => 'Sigo sin poder entrar.',
        ])->assertOk();

        $this->assertDatabaseCount('support_tickets', 1);
        $this->assertSame(2, $existingTicket->messages()->count());
        Event::assertNotDispatched(SupportTicketEscalated::class);
    }

    public function test_the_login_field_can_also_resolve_by_email_or_username(): void
    {
        $user = User::factory()->create();

        $this->postJson(route('login-support.store'), [
            'login' => $user->email,
            'message' => 'No me llega nada.',
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('support_tickets', ['user_id' => $user->id]);
    }

    public function test_the_message_is_required(): void
    {
        $user = User::factory()->create(['phone' => '+593991234567']);

        $this->postJson(route('login-support.store'), ['login' => $user->phone])
            ->assertJsonValidationErrors('message');
    }
}
