<?php

namespace Tests\Feature\WhatsApp;

use App\Models\ChatbotConversation;
use App\Models\Fleet;
use App\Models\RideRequest;
use App\Models\User;
use App\Services\Chatbot\ChatbotEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pedido explícito del usuario, con captura real de WhatsApp: pidió una
 * carrera, ningún conductor la aceptó, y al escribir de nuevo ("Que paso?")
 * el bot no reconoció que tenía una solicitud pendiente — le mandó el menú
 * genérico. "Si no hay conductores el cliente tiene que recibir el
 * feedback... y que le muestre los botones para cancelar solicitud o
 * permanecer."
 */
class WhatsAppPendingRequestTest extends TestCase
{
    use RefreshDatabase;

    private function enableWhatsApp(): void
    {
        Config::set('services.whatsapp.token', 'fake-token');
        Config::set('services.whatsapp.phone_number_id', '123456');
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.fake']]], 200)]);
    }

    public function test_any_message_while_a_ride_request_is_pending_gets_the_real_status_instead_of_the_generic_menu(): void
    {
        $this->enableWhatsApp();
        $client = User::factory()->create(['phone' => '+593991234567']);
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $rideRequest = RideRequest::factory()->for($fleet)->for($client, 'client')->create(['status' => 'pending']);

        app(ChatbotEngine::class)->respondTo($client->phone, $client, 'Que paso?');

        Http::assertSent(function ($request) use ($rideRequest) {
            $body = $request['interactive']['body']['text'] ?? '';
            $buttonIds = collect($request['interactive']['action']['buttons'] ?? [])->pluck('reply.id');

            return str_contains($body, "#{$rideRequest->id}")
                && str_contains($body, 'buscando un conductor')
                && $buttonIds->contains('wa_pending_wait')
                && $buttonIds->contains('wa_pending_cancel');
        });
    }

    public function test_seguir_esperando_confirms_without_cancelling_anything(): void
    {
        $this->enableWhatsApp();
        $client = User::factory()->create(['phone' => '+593991234567']);
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $rideRequest = RideRequest::factory()->for($fleet)->for($client, 'client')->create(['status' => 'pending']);

        app(ChatbotEngine::class)->respondTo($client->phone, $client, 'wa_pending_wait');

        $this->assertSame('pending', $rideRequest->fresh()->status);
        Http::assertSent(fn ($request) => str_contains($request['text']['body'] ?? '', 'seguimos buscando'));
    }

    public function test_cancelar_solicitud_actually_cancels_the_pending_request(): void
    {
        $this->enableWhatsApp();
        $client = User::factory()->create(['phone' => '+593991234567']);
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $rideRequest = RideRequest::factory()->for($fleet)->for($client, 'client')->create(['status' => 'pending']);

        app(ChatbotEngine::class)->respondTo($client->phone, $client, 'wa_pending_cancel');

        $this->assertSame('cancelled', $rideRequest->fresh()->status);
        Http::assertSent(fn ($request) => str_contains($request['text']['body'] ?? '', 'cancelamos su solicitud'));
    }

    /**
     * Con una solicitud pendiente, el mensaje "pedir carrera" tampoco
     * arranca una reserva nueva por encima de la que ya está en curso —
     * primero se resuelve qué pasa con esa.
     */
    public function test_pedir_carrera_with_a_pending_request_shows_the_status_instead_of_starting_a_new_booking(): void
    {
        $this->enableWhatsApp();
        $client = User::factory()->create(['phone' => '+593991234567']);
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        RideRequest::factory()->for($fleet)->for($client, 'client')->create(['status' => 'pending']);

        app(ChatbotEngine::class)->respondTo($client->phone, $client, 'pedir carrera');

        $this->assertNull(ChatbotConversation::forPhone($client->phone)->pending_intent);
        Http::assertSent(fn ($request) => str_contains($request['interactive']['body']['text'] ?? '', 'sigue'));
    }

    public function test_a_client_without_any_pending_request_is_not_affected(): void
    {
        $this->enableWhatsApp();
        $client = User::factory()->create(['phone' => '+593991234567']);

        app(ChatbotEngine::class)->respondTo($client->phone, $client, 'Hola');

        // El saludo normal de siempre — nada de "sigue buscando".
        Http::assertNotSent(fn ($request) => str_contains($request['interactive']['body']['text'] ?? '', 'sigue'));
    }
}
