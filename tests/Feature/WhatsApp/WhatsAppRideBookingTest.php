<?php

namespace Tests\Feature\WhatsApp;

use App\Models\ChatbotConversation;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\User;
use App\Models\WhatsAppSetting;
use App\Services\Chatbot\ChatbotEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppRideBookingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_client_can_create_an_immediate_fleet_request_from_whatsapp(): void
    {
        WhatsAppSetting::current()->update(['client_ride_booking_enabled' => true]);
        $client = User::factory()->create([
            'phone' => '+593991111111',
            'whatsapp_privacy_accepted_at' => now(),
        ]);
        $driver = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        DriverProfile::factory()->for($driver)->create([
            'is_available' => true,
            'current_lat' => -2.138,
            'current_lng' => -79.895,
        ]);
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        $engine = app(ChatbotEngine::class);
        $engine->respondTo($client->phone, $client, 'pedir carrera');
        $engine->respondTo($client->phone, $client, 'wa_when_now');
        $engine->respondTo($client->phone, $client, '[ubicacion]', [
            'type' => 'location', 'location' => ['lat' => -2.137, 'lng' => -79.894, 'address' => 'Alborada', 'name' => null],
        ]);
        $engine->respondTo($client->phone, $client, '[ubicacion]', [
            'type' => 'location', 'location' => ['lat' => -2.167, 'lng' => -79.900, 'address' => 'Centro', 'name' => null],
        ]);
        $engine->respondTo($client->phone, $client, 'wa_pool_fleet');
        $engine->respondTo($client->phone, $client, 'wa_booking_confirm');

        $this->assertDatabaseHas('ride_requests', [
            'client_user_id' => $client->id,
            'fleet_id' => $fleet->id,
            'origin_address' => 'Alborada',
            'destination_address' => 'Centro',
            'is_scheduled' => false,
        ]);
        $this->assertNull(ChatbotConversation::forPhone($client->phone)->pending_intent);
        // Con QUEUE_CONNECTION=sync el job diferido de 30 segundos se
        // ejecuta inmediatamente y puede expirar la oferta en el test. Lo
        // importante aquí es que el canal creó la misma solicitud real.
    }

    public function test_booking_stops_immediately_when_admin_disables_it(): void
    {
        $client = User::factory()->create(['phone' => '+593992222222', 'whatsapp_privacy_accepted_at' => now()]);

        app(ChatbotEngine::class)->respondTo($client->phone, $client, 'pedir carrera');

        $this->assertNull(ChatbotConversation::forPhone($client->phone)->pending_intent);
        $this->assertDatabaseCount('ride_requests', 0);
    }
}
