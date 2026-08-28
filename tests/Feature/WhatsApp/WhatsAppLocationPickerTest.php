<?php

namespace Tests\Feature\WhatsApp;

use App\Models\ChatbotConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Pedido explícito del usuario: además de la dirección escrita, el bot manda
 * un enlace para abrir un mapa, buscar el lugar ahí y mandar coordenadas
 * exactas — ver WhatsAppRideBookingHandler::askLocation()/locationPickerLink()
 * y WhatsAppLocationPickerController.
 */
class WhatsAppLocationPickerTest extends TestCase
{
    use RefreshDatabase;

    private function conversationAwaiting(string $state): ChatbotConversation
    {
        $client = User::factory()->create(['phone' => '+593992222222']);

        return ChatbotConversation::create([
            'phone' => $client->phone,
            'user_id' => $client->id,
            'pending_intent' => $state,
            'context' => [],
        ]);
    }

    public function test_a_valid_signed_link_renders_the_picker(): void
    {
        $conversation = $this->conversationAwaiting('WA_BOOKING_ORIGIN');
        $link = URL::temporarySignedRoute('whatsapp.location-picker.show', now()->addMinutes(30), [
            'conversation' => $conversation->id, 'step' => 'origin',
        ]);

        $this->get($link)->assertOk()->assertInertia(fn ($page) => $page
            ->component('WhatsApp/LocationPicker')
            ->where('step', 'origin')
            ->where('valid', true)
            ->where('submitted', false));
    }

    public function test_a_link_for_the_wrong_step_is_marked_invalid(): void
    {
        // La conversación ya avanzó a destino (por ejemplo, confirmó el
        // origen por WhatsApp) — el enlace de origen que mandó el bot antes
        // ya no debe poder reusarse.
        $conversation = $this->conversationAwaiting('WA_BOOKING_DESTINATION');
        $link = URL::temporarySignedRoute('whatsapp.location-picker.show', now()->addMinutes(30), [
            'conversation' => $conversation->id, 'step' => 'origin',
        ]);

        $this->get($link)->assertOk()->assertInertia(fn ($page) => $page->where('valid', false));
    }

    public function test_an_unsigned_url_is_rejected(): void
    {
        $conversation = $this->conversationAwaiting('WA_BOOKING_ORIGIN');

        $this->get("/whatsapp/ubicacion/{$conversation->id}/origin")->assertForbidden();
    }

    public function test_submitting_a_point_advances_the_conversation_to_destination(): void
    {
        $conversation = $this->conversationAwaiting('WA_BOOKING_ORIGIN');
        $link = URL::temporarySignedRoute('whatsapp.location-picker.show', now()->addMinutes(30), [
            'conversation' => $conversation->id, 'step' => 'origin',
        ]);

        $this->post($link, ['lat' => -2.137, 'lng' => -79.894, 'address' => 'Alborada, Guayaquil'])
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('WhatsApp/LocationPicker')->where('submitted', true));

        $conversation->refresh();
        $this->assertSame('WA_BOOKING_DESTINATION', $conversation->pending_intent);
        $this->assertSame('Alborada, Guayaquil', $conversation->context['origin']['address']);
        $this->assertSame(-2.137, $conversation->context['origin']['lat']);
    }

    public function test_submitting_after_the_step_already_moved_on_is_rejected(): void
    {
        $conversation = $this->conversationAwaiting('WA_BOOKING_DESTINATION');
        $link = URL::temporarySignedRoute('whatsapp.location-picker.show', now()->addMinutes(30), [
            'conversation' => $conversation->id, 'step' => 'origin',
        ]);

        $this->post($link, ['lat' => -2.137, 'lng' => -79.894, 'address' => 'Alborada'])
            ->assertSessionHasErrors('step');
        $this->assertSame('WA_BOOKING_DESTINATION', $conversation->fresh()->pending_intent);
    }
}
