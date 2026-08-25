<?php

namespace Tests\Feature\WhatsApp;

use App\Models\ChatbotConversation;
use App\Models\Cooperative;
use App\Models\CooperativeDriverMembership;
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
            // Fijo, no aleatorio (la fábrica sortea 1-4 por defecto): el
            // test manda 2 pasajeros, y con un sorteo de 1 fallaría por
            // capacidad de forma intermitente.
            'passenger_capacity' => 4,
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
        $engine->respondTo($client->phone, $client, '2');
        $engine->respondTo($client->phone, $client, 'wa_pool_fleet');
        $engine->respondTo($client->phone, $client, 'wa_booking_confirm');

        $this->assertDatabaseHas('ride_requests', [
            'client_user_id' => $client->id,
            'fleet_id' => $fleet->id,
            'origin_address' => 'Alborada',
            'destination_address' => 'Centro',
            'is_scheduled' => false,
            'passenger_count' => 2,
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

    /**
     * Pedido explícito del usuario: "si ese numero ya esta registrado que
     * busque si tiene flota y si no tiene indicarle que se buscara un
     * conductor de las cooperativas mas cercanas a su punto de partida y
     * que confirme SI, o No. y alli mismo que le diga el costo aproximado".
     * Sin flota propia, el selector "Mi flota/Cooperativas/Públicos" se
     * salta y busca solo la cooperativa aprobada más cercana con
     * conductores elegibles de verdad.
     */
    public function test_a_client_without_a_fleet_gets_an_automatic_nearest_cooperative_offer_with_a_price(): void
    {
        WhatsAppSetting::current()->update(['client_ride_booking_enabled' => true]);
        $client = User::factory()->create([
            'phone' => '+593991111133',
            'whatsapp_privacy_accepted_at' => now(),
        ]);
        // El cliente tiene una flota (siempre se crea una por defecto), pero
        // vacía — sin conductores, cuenta como "sin flota" para este flujo.
        Fleet::factory()->for($client, 'owner')->create();

        $cooperativeOwner = User::factory()->create();
        $cooperative = Cooperative::query()->create([
            'user_id' => $cooperativeOwner->id,
            'name' => 'Coop Cercana',
            'stand_lat' => -2.1690,
            'stand_lng' => -79.8990,
        ]);
        $cooperative->forceFill(['status' => 'approved'])->save();

        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create([
            'driver_type' => 'public_transport',
            'current_lat' => -2.1700,
            'current_lng' => -79.9000,
            'passenger_capacity' => 4,
            'rate_per_km' => 0.5,
        ]);
        CooperativeDriverMembership::query()->create([
            'cooperative_id' => $cooperative->id,
            'driver_user_id' => $driver->id,
            'invited_by_user_id' => $cooperativeOwner->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);

        $engine = app(ChatbotEngine::class);
        $engine->respondTo($client->phone, $client, 'pedir carrera');
        $engine->respondTo($client->phone, $client, 'wa_when_now');
        $engine->respondTo($client->phone, $client, '[ubicacion]', [
            'type' => 'location', 'location' => ['lat' => -2.1701, 'lng' => -79.9001, 'address' => 'Origen', 'name' => null],
        ]);
        $engine->respondTo($client->phone, $client, '[ubicacion]', [
            'type' => 'location', 'location' => ['lat' => -2.1800, 'lng' => -79.9100, 'address' => 'Destino', 'name' => null],
        ]);
        $engine->respondTo($client->phone, $client, '1');

        // Sin selector: ya debería estar esperando la confirmación con precio.
        $conversation = ChatbotConversation::forPhone($client->phone);
        $this->assertSame('WA_BOOKING_CONFIRM', $conversation->pending_intent);
        $this->assertSame($cooperative->id, $conversation->context['cooperative_id']);
        $this->assertArrayHasKey('estimated_price', $conversation->context);

        $engine->respondTo($client->phone, $client, 'wa_booking_confirm');

        $this->assertDatabaseHas('ride_requests', [
            'client_user_id' => $client->id,
            'cooperative_id' => $cooperative->id,
            'origin_address' => 'Origen',
            'destination_address' => 'Destino',
        ]);
    }

    public function test_a_client_without_a_fleet_and_no_nearby_cooperative_candidates_gets_a_clear_message(): void
    {
        WhatsAppSetting::current()->update(['client_ride_booking_enabled' => true]);
        $client = User::factory()->create([
            'phone' => '+593991111144',
            'whatsapp_privacy_accepted_at' => now(),
        ]);
        Fleet::factory()->for($client, 'owner')->create();

        $engine = app(ChatbotEngine::class);
        $engine->respondTo($client->phone, $client, 'pedir carrera');
        $engine->respondTo($client->phone, $client, 'wa_when_now');
        $engine->respondTo($client->phone, $client, '[ubicacion]', [
            'type' => 'location', 'location' => ['lat' => -2.1701, 'lng' => -79.9001, 'address' => 'Origen', 'name' => null],
        ]);
        $engine->respondTo($client->phone, $client, '[ubicacion]', [
            'type' => 'location', 'location' => ['lat' => -2.1800, 'lng' => -79.9100, 'address' => 'Destino', 'name' => null],
        ]);
        $engine->respondTo($client->phone, $client, '1');

        $this->assertNull(ChatbotConversation::forPhone($client->phone)->pending_intent);
        $this->assertDatabaseCount('ride_requests', 0);
    }

    /**
     * Pedido explícito del usuario: "confirmar el nombre con dos botones si,
     * cambiar, y luego continua" — antes se creaba la cuenta apenas se
     * escribía el nombre, sin poder corregir un typo.
     */
    public function test_an_unregistered_number_confirms_the_name_before_the_account_is_created(): void
    {
        WhatsAppSetting::current()->update(['client_ride_booking_enabled' => true]);
        $phone = '+593991111155';

        $engine = app(ChatbotEngine::class);
        $engine->respondTo($phone, null, 'pedir carrera');
        $engine->respondTo($phone, null, 'wa_privacy_accept');
        $engine->respondTo($phone, null, 'Ana Lopz');

        $this->assertDatabaseMissing('users', ['phone' => $phone]);

        $engine->respondTo($phone, null, 'wa_name_retry');
        $engine->respondTo($phone, null, 'Ana Lopez');
        $engine->respondTo($phone, null, 'wa_name_confirm');

        $this->assertDatabaseHas('users', ['phone' => $phone, 'name' => 'Ana Lopez', 'role' => 'cliente']);
        $this->assertSame('WA_BOOKING_WHEN', ChatbotConversation::forPhone($phone)->pending_intent);
    }
}
