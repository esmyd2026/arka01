<?php

namespace Tests\Feature\Api\V1;

use App\Events\SupportMessageSent;
use App\Models\DriverProfile;
use App\Models\Faq;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Centro de Ayuda / Soporte desde la app móvil (roadmap Hito 2, pasada
 * "full backend"). Reusa App\Services\Support\SupportCenterService, la
 * misma lógica que la web (tests\Feature\Support\SupportCenterTest).
 */
class MobileSupportTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_a_client_only_sees_client_and_shared_faqs(): void
    {
        Faq::query()->create(['audience' => 'cliente', 'category' => 'Carreras', 'question' => 'Pregunta cliente extra', 'answer' => 'R']);
        Faq::query()->create(['audience' => 'conductor', 'category' => 'Documentos', 'question' => 'Pregunta conductor extra', 'answer' => 'R']);

        $client = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->getJson('/api/v1/support');

        $response->assertOk();
        $questions = collect($response->json('faqs'))->pluck('question');
        $this->assertTrue($questions->contains('Pregunta cliente extra'));
        $this->assertFalse($questions->contains('Pregunta conductor extra'));
    }

    public function test_a_driver_only_sees_driver_and_shared_faqs(): void
    {
        Faq::query()->create(['audience' => 'cliente', 'category' => 'Carreras', 'question' => 'Pregunta cliente extra', 'answer' => 'R']);
        Faq::query()->create(['audience' => 'conductor', 'category' => 'Documentos', 'question' => 'Pregunta conductor extra', 'answer' => 'R']);

        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->getJson('/api/v1/support');

        $response->assertOk();
        $questions = collect($response->json('faqs'))->pluck('question');
        $this->assertTrue($questions->contains('Pregunta conductor extra'));
        $this->assertFalse($questions->contains('Pregunta cliente extra'));
    }

    public function test_without_a_ticket_it_reports_null_and_no_messages(): void
    {
        $client = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->getJson('/api/v1/support');

        $response->assertOk()
            ->assertJsonPath('ticket', null)
            ->assertJsonCount(0, 'messages');
    }

    public function test_sending_the_first_message_creates_a_new_ticket(): void
    {
        Event::fake([SupportMessageSent::class]);
        $client = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->postJson('/api/v1/support/messages', ['body' => 'Tengo un problema con mi cuenta.']);

        $response->assertCreated();
        $this->assertDatabaseHas('support_tickets', ['user_id' => $client->id, 'status' => 'nuevo']);
        $this->assertDatabaseHas('support_ticket_messages', ['sender_user_id' => $client->id, 'body' => 'Tengo un problema con mi cuenta.']);
        Event::assertDispatched(SupportMessageSent::class);
    }

    public function test_a_second_message_reuses_the_same_open_ticket(): void
    {
        $client = User::factory()->create();
        $token = $this->tokenFor($client);

        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/support/messages', ['body' => 'Primero']);
        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/support/messages', ['body' => 'Segundo']);

        $this->assertSame(1, SupportTicket::where('user_id', $client->id)->count());
        $ticket = SupportTicket::where('user_id', $client->id)->firstOrFail();
        $this->assertSame(2, $ticket->messages()->count());
    }

    public function test_replying_to_a_resolved_ticket_reopens_it(): void
    {
        $client = User::factory()->create();
        SupportTicket::factory()->for($client)->create(['status' => 'resuelto']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->postJson('/api/v1/support/messages', ['body' => 'Sigo con el problema.'])
            ->assertCreated()
            ->assertJsonPath('ticket_status', 'nuevo');
    }

    public function test_cannot_message_a_closed_ticket_directly(): void
    {
        $client = User::factory()->create();
        $ticket = SupportTicket::factory()->for($client)->create(['status' => 'nuevo']);
        // Se cierra justo antes de mandar el mensaje (condición de carrera simulada).
        $ticket->update(['status' => 'cerrado']);

        // openOrCreateFor() abre uno NUEVO al ver que el único existente está
        // cerrado, así que en la práctica esto termina creando otro ticket en
        // vez de fallar — se confirma ese comportamiento acá también.
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->postJson('/api/v1/support/messages', ['body' => 'Necesito ayuda de nuevo.'])
            ->assertCreated();

        $this->assertSame(2, SupportTicket::where('user_id', $client->id)->count());
    }

    public function test_it_requires_a_token(): void
    {
        $this->getJson('/api/v1/support')->assertUnauthorized();
        $this->postJson('/api/v1/support/messages', ['body' => 'x'])->assertUnauthorized();
    }
}
