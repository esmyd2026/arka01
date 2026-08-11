<?php

namespace Tests\Feature\Support;

use App\Events\SupportMessageSent;
use App\Models\DriverProfile;
use App\Models\Faq;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Centro de Ayuda (roadmap de mejoras, secciones 11 y 12): preguntas
 * frecuentes por rol + "Hablar con soporte" con un ticket por usuario.
 */
class SupportCenterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * La migración ya siembra un catálogo inicial de FAQs por rol (mismo
     * criterio que rating_reasons) — por eso estos tests verifican presencia/
     * ausencia de la pregunta propia, no un conteo exacto.
     */
    public function test_a_client_only_sees_client_and_shared_faqs(): void
    {
        Faq::query()->create(['audience' => 'cliente', 'category' => 'Carreras', 'question' => 'Pregunta cliente extra', 'answer' => 'R']);
        Faq::query()->create(['audience' => 'conductor', 'category' => 'Documentos', 'question' => 'Pregunta conductor extra', 'answer' => 'R']);

        $client = User::factory()->create();

        $response = $this->actingAs($client)->get(route('support.index'));

        $response->assertInertia(fn ($page) => $page->where(
            'faqs',
            fn ($faqs) => collect($faqs)->pluck('question')->contains('Pregunta cliente extra')
                && ! collect($faqs)->pluck('question')->contains('Pregunta conductor extra')
        ));
    }

    public function test_a_driver_only_sees_driver_and_shared_faqs(): void
    {
        Faq::query()->create(['audience' => 'cliente', 'category' => 'Carreras', 'question' => 'Pregunta cliente extra', 'answer' => 'R']);
        Faq::query()->create(['audience' => 'conductor', 'category' => 'Documentos', 'question' => 'Pregunta conductor extra', 'answer' => 'R']);

        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $response = $this->actingAs($driver)->get(route('support.index'));

        $response->assertInertia(fn ($page) => $page->where(
            'faqs',
            fn ($faqs) => collect($faqs)->pluck('question')->contains('Pregunta conductor extra')
                && ! collect($faqs)->pluck('question')->contains('Pregunta cliente extra')
        ));
    }

    public function test_inactive_faqs_are_not_shown(): void
    {
        Faq::query()->create(['audience' => 'ambos', 'category' => 'General', 'question' => 'Activa extra', 'answer' => 'R', 'is_active' => true]);
        Faq::query()->create(['audience' => 'ambos', 'category' => 'General', 'question' => 'Inactiva extra', 'answer' => 'R', 'is_active' => false]);

        $client = User::factory()->create();

        $response = $this->actingAs($client)->get(route('support.index'));

        $response->assertInertia(fn ($page) => $page->where(
            'faqs',
            fn ($faqs) => collect($faqs)->pluck('question')->contains('Activa extra')
                && ! collect($faqs)->pluck('question')->contains('Inactiva extra')
        ));
    }

    public function test_sending_the_first_message_creates_a_new_ticket(): void
    {
        Event::fake([SupportMessageSent::class]);
        $client = User::factory()->create();

        $response = $this->actingAs($client)->postJson(route('support.messages.store'), [
            'body' => 'Tengo un problema con mi cuenta.',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('support_tickets', ['user_id' => $client->id, 'status' => 'nuevo']);
        $this->assertDatabaseHas('support_ticket_messages', ['sender_user_id' => $client->id, 'body' => 'Tengo un problema con mi cuenta.']);
        Event::assertDispatched(SupportMessageSent::class);
    }

    /**
     * Pedido explícito del usuario: "Hablar con soporte" retoma el mismo
     * ticket mientras no esté cerrado, no crea uno nuevo cada vez.
     */
    public function test_a_second_message_reuses_the_same_open_ticket(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client)->postJson(route('support.messages.store'), ['body' => 'Primero']);
        $this->actingAs($client)->postJson(route('support.messages.store'), ['body' => 'Segundo']);

        $this->assertSame(1, SupportTicket::where('user_id', $client->id)->count());
        $ticket = SupportTicket::where('user_id', $client->id)->firstOrFail();
        $this->assertSame(2, $ticket->messages()->count());
    }

    public function test_a_closed_ticket_starts_a_new_one_instead_of_reopening(): void
    {
        $client = User::factory()->create();
        $oldTicket = SupportTicket::factory()->for($client)->create(['status' => 'cerrado']);

        $this->actingAs($client)->postJson(route('support.messages.store'), ['body' => 'Necesito ayuda de nuevo.']);

        $this->assertSame(2, SupportTicket::where('user_id', $client->id)->count());
        $newTicket = SupportTicket::where('user_id', $client->id)->where('id', '!=', $oldTicket->id)->firstOrFail();
        $this->assertSame('nuevo', $newTicket->status);
    }

    /**
     * Pedido explícito del usuario: si soporte lo había dejado "esperando
     * usuario" o "resuelto", escribir de nuevo lo vuelve a poner pendiente.
     */
    public function test_replying_to_a_resolved_ticket_reopens_it(): void
    {
        $client = User::factory()->create();
        SupportTicket::factory()->for($client)->create(['status' => 'resuelto']);

        $this->actingAs($client)->postJson(route('support.messages.store'), ['body' => 'Sigo con el problema.']);

        $ticket = SupportTicket::where('user_id', $client->id)->firstOrFail();
        $this->assertSame('nuevo', $ticket->status);
    }
}
