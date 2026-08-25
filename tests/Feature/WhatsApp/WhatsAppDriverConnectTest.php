<?php

namespace Tests\Feature\WhatsApp;

use App\Models\ChatbotConversation;
use App\Models\DriverProfile;
use App\Models\User;
use App\Services\Chatbot\ChatbotEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Soy Conductor" (pedido explícito del usuario): conectarse/desconectarse
 * desde WhatsApp sin abrir la app, y el mini-registro de conductor para un
 * número sin cuenta.
 */
class WhatsAppDriverConnectTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_disconnected_driver_can_connect_from_whatsapp(): void
    {
        $driver = User::factory()->create(['phone' => '+593993330001']);
        DriverProfile::factory()->for($driver)->create(['is_available' => false]);

        $engine = app(ChatbotEngine::class);
        $engine->respondTo($driver->phone, $driver, 'conectarme');
        $engine->respondTo($driver->phone, $driver, 'wa_driver_connect');

        $this->assertTrue($driver->driverProfile->fresh()->is_available);
        $this->assertNull(ChatbotConversation::forPhone($driver->phone)->pending_intent);
    }

    public function test_a_connected_driver_can_disconnect_from_whatsapp(): void
    {
        $driver = User::factory()->create(['phone' => '+593993330002']);
        DriverProfile::factory()->for($driver)->create(['is_available' => true]);

        $engine = app(ChatbotEngine::class);
        $engine->respondTo($driver->phone, $driver, 'conectarme');
        $engine->respondTo($driver->phone, $driver, 'wa_driver_disconnect');

        $this->assertFalse($driver->driverProfile->fresh()->is_available);
    }

    /**
     * Mismo motivo de bloqueo que ya usa el toggle de la app
     * (DriverProfile::availabilityBlockReason()) — acá no debe poder
     * conectarse tampoco.
     */
    public function test_a_suspended_driver_cannot_connect_from_whatsapp(): void
    {
        $driver = User::factory()->create(['phone' => '+593993330003']);
        DriverProfile::factory()->for($driver)->create(['is_available' => false]);
        $driver->driverProfile->forceFill(['suspended_at' => now()])->save();

        app(ChatbotEngine::class)->respondTo($driver->phone, $driver, 'conectarme');

        $this->assertFalse($driver->driverProfile->fresh()->is_available);
    }

    public function test_an_unregistered_number_creates_a_driver_account_after_consent_and_name_confirmation(): void
    {
        $phone = '+593993330004';

        $engine = app(ChatbotEngine::class);
        $engine->respondTo($phone, null, 'soy conductor');
        $engine->respondTo($phone, null, 'wa_driver_consent_accept');
        $engine->respondTo($phone, null, 'Pedro Ruiz');
        $engine->respondTo($phone, null, 'wa_name_confirm');

        $this->assertDatabaseHas('users', ['phone' => $phone, 'name' => 'Pedro Ruiz', 'role' => 'conductor']);
        $this->assertNull(ChatbotConversation::forPhone($phone)->pending_intent);
    }

    /**
     * Pedido explícito del usuario: "confirmar el nombre con dos botones si,
     * cambiar" — tocar Cambiar vuelve a pedir el nombre en vez de crear la
     * cuenta con un typo.
     */
    public function test_choosing_cambiar_lets_the_driver_retype_the_name_before_creating_the_account(): void
    {
        $phone = '+593993330005';

        $engine = app(ChatbotEngine::class);
        $engine->respondTo($phone, null, 'soy conductor');
        $engine->respondTo($phone, null, 'wa_driver_consent_accept');
        $engine->respondTo($phone, null, 'Pedro Ruis');
        $engine->respondTo($phone, null, 'wa_name_retry');
        $engine->respondTo($phone, null, 'Pedro Ruiz');
        $engine->respondTo($phone, null, 'wa_name_confirm');

        $this->assertDatabaseMissing('users', ['phone' => $phone, 'name' => 'Pedro Ruis']);
        $this->assertDatabaseHas('users', ['phone' => $phone, 'name' => 'Pedro Ruiz', 'role' => 'conductor']);
    }

    public function test_declining_consent_creates_no_account(): void
    {
        $phone = '+593993330006';

        $engine = app(ChatbotEngine::class);
        $engine->respondTo($phone, null, 'soy conductor');
        $engine->respondTo($phone, null, 'wa_driver_consent_decline');

        $this->assertDatabaseMissing('users', ['phone' => $phone]);
    }
}
