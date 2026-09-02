<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\NotifyWhatsAppStillSearchingForDriver;
use App\Models\ChatbotConversation;
use App\Models\Cooperative;
use App\Models\CooperativeDriverMembership;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\RideRequest;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Models\WhatsAppSetting;
use App\Services\Chatbot\ChatbotEngine;
use App\Services\RideDispatchAdvancer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppRideBookingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Pedido explícito del usuario: un conductor de cooperativa necesita un
     * plan pago vigente para que el despacho automático lo considere (ver
     * PlanLimits::hasActivePaidPlan()) — sin esto, RideDispatchCandidates::forCooperative()
     * lo excluye y estos tests de reserva automática por cooperativa se
     * quedan sin candidato.
     */
    private function giveActivePaidPlan(User $driver): void
    {
        $plan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'plus')->firstOrFail();
        Subscription::factory()->for($driver)->create(['subscription_plan_id' => $plan->id, 'status' => 'active']);
    }

    public function test_origin_and_destination_use_the_native_whatsapp_map_button(): void
    {
        WhatsAppSetting::current()->update(['client_ride_booking_enabled' => true]);
        Config::set('services.whatsapp.token', 'fake-token');
        Config::set('services.whatsapp.phone_number_id', '123456');
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.fake']]], 200),
        ]);

        $client = User::factory()->create([
            'phone' => '+593991111100',
            'whatsapp_privacy_accepted_at' => now(),
        ]);

        $engine = app(ChatbotEngine::class);
        $engine->respondTo($client->phone, $client, 'pedir carrera');
        $engine->respondTo($client->phone, $client, 'wa_when_now');
        $engine->respondTo($client->phone, $client, '[ubicacion]', [
            'type' => 'location',
            'location' => ['lat' => -2.137, 'lng' => -79.894, 'address' => 'Alborada', 'name' => null],
        ]);

        $locationRequests = collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0])
            ->filter(fn ($request) => ($request['interactive']['type'] ?? null) === 'location_request_message')
            ->values();

        $this->assertCount(2, $locationRequests);
        $this->assertSame('send_location', $locationRequests[0]['interactive']['action']['name']);
        $this->assertStringContainsString('¿Desde dónde le recogemos?', $locationRequests[0]['interactive']['body']['text']);
        $this->assertStringContainsString('¿A dónde vamos?', $locationRequests[1]['interactive']['body']['text']);
        $this->assertStringNotContainsString('http', $locationRequests[0]['interactive']['body']['text']);
        $this->assertStringNotContainsString('http', $locationRequests[1]['interactive']['body']['text']);
        $this->assertSame('WA_BOOKING_DESTINATION', ChatbotConversation::forPhone($client->phone)->pending_intent);
    }

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
        $engine->respondTo($client->phone, $client, 'wa_select_driver:'.$driver->id);
        $engine->respondTo($client->phone, $client, 'wa_booking_confirm');

        $this->assertDatabaseHas('ride_requests', [
            'client_user_id' => $client->id,
            'fleet_id' => $fleet->id,
            'driver_user_id' => $driver->id,
            'origin_address' => 'Alborada',
            'destination_address' => 'Centro',
            'is_scheduled' => false,
            'passenger_count' => 2,
        ]);
        $this->assertNull(ChatbotConversation::forPhone($client->phone)->pending_intent);
        // Bug real reportado por el usuario (con captura: "el valor es
        // $0.00") — WhatsAppRideBookingHandler::createRide() leía
        // `offered_price`, un atributo que no existe en el modelo (el campo
        // real es `current_offered_price`); Eloquent devolvía null en
        // silencio en vez de un error.
        $this->assertGreaterThan(0, RideRequest::where('client_user_id', $client->id)->latest('id')->first()->current_offered_price);
        // Con QUEUE_CONNECTION=sync el job diferido de 30 segundos se
        // ejecuta inmediatamente y puede expirar la oferta en el test. Lo
        // importante aquí es que el canal creó la misma solicitud real.
    }

    public function test_an_unavailable_selection_keeps_the_trip_and_offers_retry_or_other_options(): void
    {
        WhatsAppSetting::current()->update(['client_ride_booking_enabled' => true]);
        Config::set('services.whatsapp.token', 'fake-token');
        Config::set('services.whatsapp.phone_number_id', '123456');
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.fake']]], 200)]);

        $client = User::factory()->create([
            'phone' => '+593991111155',
            'whatsapp_privacy_accepted_at' => now(),
        ]);
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create([
            'is_available' => false,
            'current_lat' => -2.138,
            'current_lng' => -79.895,
            'passenger_capacity' => 4,
        ]);
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        ChatbotConversation::query()->create([
            'phone' => $client->phone,
            'user_id' => $client->id,
            'pending_intent' => 'WA_BOOKING_CONFIRM',
            'context' => [
                'origin' => ['lat' => -2.137, 'lng' => -79.894, 'address' => 'Alborada'],
                'destination' => ['lat' => -2.167, 'lng' => -79.900, 'address' => 'Centro'],
                'passenger_count' => 8,
                'is_scheduled' => false,
                'driver_user_id' => $driver->id,
                'driver_name' => $driver->full_name,
                'dispatch_pool' => 'fleet',
            ],
        ]);

        $engine = app(ChatbotEngine::class);
        $engine->respondTo($client->phone, $client, 'wa_booking_confirm');

        $conversation = ChatbotConversation::forPhone($client->phone)->fresh();
        $this->assertSame('WA_BOOKING_RECOVERY', $conversation->pending_intent);
        $this->assertSame('Alborada', $conversation->context['origin']['address']);
        $this->assertSame('Centro', $conversation->context['destination']['address']);

        $recoveryMessage = collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0])
            ->last(fn ($request) => ($request['interactive']['action']['buttons'][0]['reply']['id'] ?? null) === 'wa_booking_retry');

        $this->assertNotNull($recoveryMessage);
        $this->assertSame(
            ['wa_booking_retry', 'wa_booking_alternatives', 'wa_booking_recovery_cancel'],
            collect($recoveryMessage['interactive']['action']['buttons'])->pluck('reply.id')->all()
        );

        $engine->respondTo($client->phone, $client, 'wa_booking_alternatives');

        $this->assertSame('WA_BOOKING_SELECT', ChatbotConversation::forPhone($client->phone)->fresh()->pending_intent);
        $optionsMessage = collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0])
            ->last(fn ($request) => ($request['interactive']['type'] ?? null) === 'list');
        $optionIds = collect($optionsMessage['interactive']['action']['sections'][0]['rows'] ?? [])->pluck('id');
        $this->assertTrue($optionIds->contains('wa_select_public'));
    }

    /**
     * Pedido explícito del usuario, con captura real de WhatsApp: escribió
     * "Coronel y Calicuchima" y el bot no lo entendió sin dar ninguna
     * pista — y donde sí resolvía algo por texto, lo aceptaba directo sin
     * mostrarlo. "Que le mande lo que te retorna google map para que
     * confirme": una dirección escrita a mano se muestra con botones antes
     * de aceptarla; una ubicación COMPARTIDA (coordenadas GPS reales, ver
     * el resto de los tests de este archivo) no necesita ese paso.
     */
    public function test_a_typed_address_is_shown_back_for_confirmation_before_being_accepted(): void
    {
        WhatsAppSetting::current()->update(['client_ride_booking_enabled' => true]);
        $client = User::factory()->create([
            'phone' => '+593991111166',
            'whatsapp_privacy_accepted_at' => now(),
        ]);
        $driver = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        DriverProfile::factory()->for($driver)->create(['is_available' => true, 'current_lat' => -2.138, 'current_lng' => -79.895, 'passenger_capacity' => 4]);
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        Http::fake([
            'maps.googleapis.com/maps/api/geocode/*' => Http::response([
                'results' => [['formatted_address' => 'Av. Coronel y Calicuchima, Guayaquil', 'geometry' => ['location' => ['lat' => -2.19, 'lng' => -79.89]]]],
            ], 200),
        ]);
        Config::set('services.google_maps.server_api_key', 'fake-key');

        $engine = app(ChatbotEngine::class);
        $engine->respondTo($client->phone, $client, 'pedir carrera');
        $engine->respondTo($client->phone, $client, 'wa_when_now');
        $engine->respondTo($client->phone, $client, 'Coronel y Calicuchima');

        // Todavía no quedó aceptado como origen — espera confirmación.
        $conversation = ChatbotConversation::forPhone($client->phone);
        $this->assertSame('WA_BOOKING_CONFIRM_POINT', $conversation->pending_intent);
        $this->assertArrayNotHasKey('origin', $conversation->context);
        $this->assertSame('Av. Coronel y Calicuchima, Guayaquil', $conversation->context['pending_point']['address']);

        $engine->respondTo($client->phone, $client, 'wa_point_confirm');

        $this->assertSame('Av. Coronel y Calicuchima, Guayaquil', ChatbotConversation::forPhone($client->phone)->context['origin']['address']);
    }

    /**
     * Pedido explícito del usuario ("la ubicación que le llega al conductor
     * dice 'ubicación compartida' porque la mandó el cliente desde el mapa
     * de WhatsApp pero no le dio el detalle al chofer") — un pin suelto
     * (a diferencia de buscar un lugar con nombre) no trae `address` ni
     * `name`; se resuelve con geocoding inverso en vez de dejar el texto
     * genérico que no le sirve de nada al conductor.
     */
    public function test_a_bare_shared_location_gets_reverse_geocoded_into_a_real_address(): void
    {
        WhatsAppSetting::current()->update(['client_ride_booking_enabled' => true]);
        $client = User::factory()->create(['phone' => '+593991111177', 'whatsapp_privacy_accepted_at' => now()]);
        $driver = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        DriverProfile::factory()->for($driver)->create(['is_available' => true, 'current_lat' => -2.138, 'current_lng' => -79.895, 'passenger_capacity' => 4]);
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        Config::set('services.google_maps.server_api_key', 'fake-key');
        Http::fake([
            'maps.googleapis.com/maps/api/geocode/*' => Http::response([
                'results' => [['formatted_address' => 'Cdla. Kennedy Norte, Guayaquil']],
            ], 200),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.fake']]], 200),
        ]);
        Config::set('services.whatsapp.token', 'fake-token');
        Config::set('services.whatsapp.phone_number_id', '123456');

        $engine = app(ChatbotEngine::class);
        $engine->respondTo($client->phone, $client, 'pedir carrera');
        $engine->respondTo($client->phone, $client, 'wa_when_now');
        // Pin suelto: sin address ni name, solo coordenadas — exactamente
        // lo que manda WhatsApp cuando alguien comparte su ubicación en
        // vivo desde el mapa, en vez de buscar un lugar con nombre.
        $engine->respondTo($client->phone, $client, '[ubicacion]', [
            'type' => 'location', 'location' => ['lat' => -2.15, 'lng' => -79.90, 'address' => null, 'name' => null],
        ]);
        $engine->respondTo($client->phone, $client, '[ubicacion]', [
            'type' => 'location', 'location' => ['lat' => -2.16, 'lng' => -79.91, 'address' => 'Centro', 'name' => null],
        ]);
        $engine->respondTo($client->phone, $client, '2');
        $engine->respondTo($client->phone, $client, 'wa_select_driver:'.$driver->id);
        $engine->respondTo($client->phone, $client, 'wa_booking_confirm');

        $this->assertDatabaseHas('ride_requests', [
            'client_user_id' => $client->id,
            'origin_address' => 'Cdla. Kennedy Norte, Guayaquil',
        ]);
    }

    /**
     * Pedido explícito del usuario ("deberiamos verificar que si no tiene
     * cuenta preguntarle si quiere registrarse para luego sus solicitudes
     * sean mas rapido") — la cuenta se crea sola en la primera reserva,
     * pero con una contraseña al azar que nunca vio; se le ofrece un link
     * firmado (una sola vez, no en la segunda carrera) que la deja entrar
     * sin esa contraseña, directo a completarlo de verdad.
     */
    public function test_a_first_time_guest_gets_offered_a_link_to_finish_registering(): void
    {
        WhatsAppSetting::current()->update(['client_ride_booking_enabled' => true]);
        Config::set('services.whatsapp.token', 'fake-token');
        Config::set('services.whatsapp.phone_number_id', '123456');
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.fake']]], 200)]);
        $cooperativeOwner = User::factory()->create();
        // Pública (pedido explícito del usuario: "listarle las cooperativas
        // disponibles... desde la que esta publica o de su flota") — sin
        // esto, un invitado sin cooperativas propias no vería ninguna
        // opción en la lista de askWhoAttends().
        $cooperative = Cooperative::query()->create(['user_id' => $cooperativeOwner->id, 'name' => 'Coop', 'stand_lat' => -2.1690, 'stand_lng' => -79.8990, 'is_public' => true]);
        $cooperative->forceFill(['status' => 'approved'])->save();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['driver_type' => 'public_transport', 'current_lat' => -2.1700, 'current_lng' => -79.9000, 'passenger_capacity' => 4, 'rate_per_km' => 0.5]);
        CooperativeDriverMembership::query()->create(['cooperative_id' => $cooperative->id, 'driver_user_id' => $driver->id, 'invited_by_user_id' => $cooperativeOwner->id, 'status' => 'accepted', 'responded_at' => now()]);
        $this->giveActivePaidPlan($driver);

        $phone = '+593991111188';
        $engine = app(ChatbotEngine::class);
        $engine->respondTo($phone, null, 'pedir carrera');
        $engine->respondTo($phone, null, 'wa_privacy_accept');
        $engine->respondTo($phone, null, 'Ana Lopez');
        $engine->respondTo($phone, null, 'wa_name_confirm');

        // La cuenta invitada ya existe desde acá — igual que en la vida
        // real, donde WhatsAppWebhookController ya resuelve $user antes de
        // llamar a ChatbotEngine::respondTo() para cada mensaje siguiente.
        $user = User::query()->where('phone', $phone)->firstOrFail();
        $engine->respondTo($phone, $user, 'wa_when_now');
        $engine->respondTo($phone, $user, '[ubicacion]', ['type' => 'location', 'location' => ['lat' => -2.1701, 'lng' => -79.9001, 'address' => 'Origen', 'name' => null]]);
        $engine->respondTo($phone, $user, '[ubicacion]', ['type' => 'location', 'location' => ['lat' => -2.1800, 'lng' => -79.9100, 'address' => 'Destino', 'name' => null]]);
        $engine->respondTo($phone, $user, '1');
        $engine->respondTo($phone, $user, 'wa_select_coop:'.$cooperative->id);
        $engine->respondTo($phone, $user, 'wa_booking_confirm');

        $this->assertNull($user->fresh()->password_set_at);

        // El link firmado de verdad funciona: entra sin contraseña.
        $sentBody = collect(Http::recorded())
            ->map(fn ($pair) => $pair[0]['text']['body'] ?? null)
            ->filter(fn ($body) => $body && str_contains($body, 'Termine de registrarse'))
            ->first();
        preg_match('#https?://\S+#', $sentBody, $match);
        $this->get($match[0])->assertRedirect(route('profile.edit'));
        $this->assertAuthenticatedAs($user);
    }

    /**
     * Pedido explícito del usuario ("cuando pida nuevamente mostrarle las
     * ubicaciones que ha solicitado para volverlas a repetir") — tocar una
     * dirección anterior de la lista usa sus coordenadas EXACTAS, sin
     * volver a geocodificar ni pedir confirmación.
     */
    public function test_a_returning_client_can_repeat_a_previous_address_from_a_list(): void
    {
        WhatsAppSetting::current()->update(['client_ride_booking_enabled' => true]);
        $client = User::factory()->create(['phone' => '+593991111199', 'whatsapp_privacy_accepted_at' => now()]);
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['is_available' => true, 'current_lat' => -2.138, 'current_lng' => -79.895, 'passenger_capacity' => 4]);
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        // 'accepted' a propósito, no el 'pending' por defecto de la factory:
        // esto representa una carrera anterior ya resuelta, no una activa
        // sin aceptar — con 'pending' interceptaría WhatsAppPendingRequestHandler
        // antes de llegar a probar la lista de direcciones recientes.
        RideRequest::factory()->for($fleet)->for($client, 'client')->create([
            'status' => 'accepted',
            'origin_address' => 'Casa — Urdesa', 'origin_lat' => -2.16, 'origin_lng' => -79.90,
            'destination_address' => 'Trabajo — Centro', 'destination_lat' => -2.19, 'destination_lng' => -79.88,
        ]);

        $engine = app(ChatbotEngine::class);
        $engine->respondTo($client->phone, $client, 'pedir carrera');
        $engine->respondTo($client->phone, $client, 'wa_when_now');

        // La lista de direcciones recientes queda guardada en el contexto,
        // no hace falta volver a escribir ni geocodificar nada.
        $this->assertSame(
            'Casa — Urdesa',
            ChatbotConversation::forPhone($client->phone)->context['recent_origin_options'][0]['address']
        );

        $engine->respondTo($client->phone, $client, 'wa_recent_origin:0');

        $conversation = ChatbotConversation::forPhone($client->phone);
        $this->assertSame('Casa — Urdesa', $conversation->context['origin']['address']);
        $this->assertSame(-2.16, $conversation->context['origin']['lat']);
        $this->assertSame('WA_BOOKING_DESTINATION', $conversation->pending_intent);

        $engine->respondTo($client->phone, $client, 'wa_recent_destination:0');

        $conversation = ChatbotConversation::forPhone($client->phone);
        $this->assertSame('Trabajo — Centro', $conversation->context['destination']['address']);
        $this->assertSame('WA_BOOKING_PAX', $conversation->pending_intent);
    }

    public function test_booking_stops_immediately_when_admin_disables_it(): void
    {
        $client = User::factory()->create(['phone' => '+593992222222', 'whatsapp_privacy_accepted_at' => now()]);

        app(ChatbotEngine::class)->respondTo($client->phone, $client, 'pedir carrera');

        $this->assertNull(ChatbotConversation::forPhone($client->phone)->pending_intent);
        $this->assertDatabaseCount('ride_requests', 0);
    }

    /**
     * Corrección explícita del usuario sobre el flujo anterior ("mejor
     * listarle las cooperativas disponibles — pública o de su flota — y sus
     * conductores también de su flota, y que el seleccione"): ya no se
     * busca sola y en silencio la cooperativa más cercana ni se muestra un
     * costo aproximado antes de confirmar — sin flota propia, se le listan
     * las cooperativas disponibles (públicas o de su red) para que elija, y
     * la confirmación ya no incluye ningún precio (se define recién cuando
     * un conductor de verdad la acepta).
     */
    public function test_a_client_without_a_fleet_can_choose_a_public_cooperative_from_the_list(): void
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
            'is_public' => true,
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
        $this->giveActivePaidPlan($driver);

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

        // Sin flota, con una cooperativa pública disponible: la lista ya
        // debería tenerla como opción.
        $conversation = ChatbotConversation::forPhone($client->phone);
        $this->assertSame('WA_BOOKING_SELECT', $conversation->pending_intent);
        $this->assertContains($cooperative->id, $conversation->context['select_cooperative_ids']);

        $engine->respondTo($client->phone, $client, 'wa_select_coop:'.$cooperative->id);

        // Pedido explícito del usuario: sin precio en la confirmación.
        $confirmContext = ChatbotConversation::forPhone($client->phone)->context;
        $this->assertArrayNotHasKey('estimated_price', $confirmContext);
        $this->assertSame($cooperative->id, $confirmContext['cooperative_id']);

        $engine->respondTo($client->phone, $client, 'wa_booking_confirm');

        $this->assertDatabaseHas('ride_requests', [
            'client_user_id' => $client->id,
            'cooperative_id' => $cooperative->id,
            'origin_address' => 'Origen',
            'destination_address' => 'Destino',
        ]);
        $this->assertGreaterThan(0, RideRequest::where('client_user_id', $client->id)->latest('id')->first()->current_offered_price);
    }

    /**
     * Pedido explícito del usuario, tras probar el bot con su propio número
     * de conductor e insistir en que se le permitiera pedir la carrera
     * igual: la solicitud tiene que quedar creada de verdad (no solo
     * llegar hasta la pantalla de confirmación) — extremo a extremo,
     * incluyendo el flag `whatsapp_guest_booking` que
     * RideRequestController::store() exige para esta única excepción.
     */
    public function test_a_driver_without_a_fleet_can_actually_book_a_ride_via_a_public_cooperative(): void
    {
        WhatsAppSetting::current()->update(['client_ride_booking_enabled' => true]);
        $requester = User::factory()->create([
            'phone' => '+593991111144',
            'whatsapp_privacy_accepted_at' => now(),
        ]);
        DriverProfile::factory()->for($requester)->create();

        $cooperativeOwner = User::factory()->create();
        $cooperative = Cooperative::query()->create([
            'user_id' => $cooperativeOwner->id,
            'name' => 'Coop Cercana',
            'stand_lat' => -2.1690,
            'stand_lng' => -79.8990,
            'is_public' => true,
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
        $this->giveActivePaidPlan($driver);

        $engine = app(ChatbotEngine::class);
        $engine->respondTo($requester->phone, $requester, 'pedir carrera');
        $engine->respondTo($requester->phone, $requester, 'wa_when_now');
        $engine->respondTo($requester->phone, $requester, '[ubicacion]', [
            'type' => 'location', 'location' => ['lat' => -2.1701, 'lng' => -79.9001, 'address' => 'Origen', 'name' => null],
        ]);
        $engine->respondTo($requester->phone, $requester, '[ubicacion]', [
            'type' => 'location', 'location' => ['lat' => -2.1800, 'lng' => -79.9100, 'address' => 'Destino', 'name' => null],
        ]);
        $engine->respondTo($requester->phone, $requester, '1');
        $engine->respondTo($requester->phone, $requester, 'wa_select_coop:'.$cooperative->id);
        $engine->respondTo($requester->phone, $requester, 'wa_booking_confirm');

        $this->assertDatabaseHas('ride_requests', [
            'client_user_id' => $requester->id,
            'cooperative_id' => $cooperative->id,
            'origin_address' => 'Origen',
            'destination_address' => 'Destino',
        ]);
        $this->assertGreaterThan(0, RideRequest::where('client_user_id', $requester->id)->latest('id')->first()->current_offered_price);
        // La cuenta sigue siendo conductor — esto no le cambió el rol.
        $this->assertTrue($requester->fresh()->isDriver());
    }

    public function test_a_client_without_private_options_can_continue_with_the_public_directory(): void
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

        $conversation = ChatbotConversation::forPhone($client->phone)->fresh();
        $this->assertSame('WA_BOOKING_SELECT', $conversation->pending_intent);
        $this->assertSame('Origen', $conversation->context['origin']['address']);
        $this->assertSame('Destino', $conversation->context['destination']['address']);
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

    /**
     * Pedido explícito del usuario: "el de la cantidad de cuantas personas
     * puede ser una lista de cantidades con botones 1, menor a 4 menor a 7
     * para que sea mas rapido" — se ofrecen como botones, pero un número
     * escrito a mano sigue funcionando por las dudas.
     */
    public function test_passenger_count_uses_quick_reply_buttons_and_still_accepts_a_typed_number(): void
    {
        WhatsAppSetting::current()->update(['client_ride_booking_enabled' => true]);
        Config::set('services.whatsapp.token', 'fake-token');
        Config::set('services.whatsapp.phone_number_id', '123456');
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.fake']]], 200)]);
        $client = User::factory()->create(['phone' => '+593991112201', 'whatsapp_privacy_accepted_at' => now()]);
        // Con flota (y un conductor en ella), askWhoAttends() no limpia la
        // conversación después de guardar la cantidad — lo que interesa acá
        // es solo confirmar que ese número se haya guardado.
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        $engine = app(ChatbotEngine::class);
        $engine->respondTo($client->phone, $client, 'pedir carrera');
        $engine->respondTo($client->phone, $client, 'wa_when_now');
        $engine->respondTo($client->phone, $client, '[ubicacion]', ['type' => 'location', 'location' => ['lat' => -2.137, 'lng' => -79.894, 'address' => 'Origen', 'name' => null]]);
        $engine->respondTo($client->phone, $client, '[ubicacion]', ['type' => 'location', 'location' => ['lat' => -2.167, 'lng' => -79.900, 'address' => 'Destino', 'name' => null]]);

        $paxButtons = collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0])
            ->last(fn ($request) => ($request['interactive']['type'] ?? null) === 'button');
        $this->assertSame(
            ['wa_pax:1', 'wa_pax:3', 'wa_pax:6'],
            collect($paxButtons['interactive']['action']['buttons'])->pluck('reply.id')->all()
        );

        // Un número tipeado a mano (no una de las 3 opciones) también vale.
        $engine->respondTo($client->phone, $client, '5');
        $this->assertSame(5, ChatbotConversation::forPhone($client->phone)->context['passenger_count']);
    }

    /**
     * Pedido explícito del usuario: "podrias mejor listarle las
     * cooperativas disponibles... y sus conductores tambien de su flota. y
     * que el seleccione" — un cliente con flota Y cooperativas ve las dos
     * cosas juntas en una sola lista, no un selector de categorías.
     */
    public function test_the_selection_list_combines_fleet_drivers_and_cooperatives(): void
    {
        WhatsAppSetting::current()->update(['client_ride_booking_enabled' => true]);
        $client = User::factory()->create(['phone' => '+593991112202', 'whatsapp_privacy_accepted_at' => now()]);
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $fleetDriver = User::factory()->create(['name' => 'Pedro Flota']);
        DriverProfile::factory()->for($fleetDriver)->create();
        FleetMember::factory()->for($fleet)->for($fleetDriver, 'driver')->create(['added_by' => $client->id]);

        $cooperativeOwner = User::factory()->create();
        $cooperative = Cooperative::query()->create([
            'user_id' => $cooperativeOwner->id, 'name' => 'Coop Amazonas',
            'stand_lat' => -2.1690, 'stand_lng' => -79.8990, 'is_public' => true,
        ]);
        $cooperative->forceFill(['status' => 'approved'])->save();

        $engine = app(ChatbotEngine::class);
        $engine->respondTo($client->phone, $client, 'pedir carrera');
        $engine->respondTo($client->phone, $client, 'wa_when_now');
        $engine->respondTo($client->phone, $client, '[ubicacion]', ['type' => 'location', 'location' => ['lat' => -2.1701, 'lng' => -79.9001, 'address' => 'Origen', 'name' => null]]);
        $engine->respondTo($client->phone, $client, '[ubicacion]', ['type' => 'location', 'location' => ['lat' => -2.1800, 'lng' => -79.9100, 'address' => 'Destino', 'name' => null]]);
        $engine->respondTo($client->phone, $client, 'wa_pax:1');

        $context = ChatbotConversation::forPhone($client->phone)->context;
        $this->assertSame('WA_BOOKING_SELECT', ChatbotConversation::forPhone($client->phone)->pending_intent);
        $this->assertContains($fleetDriver->id, $context['select_driver_ids']);
        $this->assertContains($cooperative->id, $context['select_cooperative_ids']);
    }

    /**
     * Pedido explícito del usuario: "en cuanto al mensaje de confirmar
     * mejor que no coloques los precios de la carreras, dejemos que cuando
     * se asigne el conductor se maneje el precio."
     */
    public function test_the_confirmation_message_never_shows_a_price(): void
    {
        WhatsAppSetting::current()->update(['client_ride_booking_enabled' => true]);
        Config::set('services.whatsapp.token', 'fake-token');
        Config::set('services.whatsapp.phone_number_id', '123456');
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.fake']]], 200)]);
        $client = User::factory()->create(['phone' => '+593991112203', 'whatsapp_privacy_accepted_at' => now()]);
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['is_available' => true, 'current_lat' => -2.138, 'current_lng' => -79.895, 'passenger_capacity' => 4]);
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        $engine = app(ChatbotEngine::class);
        $engine->respondTo($client->phone, $client, 'pedir carrera');
        $engine->respondTo($client->phone, $client, 'wa_when_now');
        $engine->respondTo($client->phone, $client, '[ubicacion]', ['type' => 'location', 'location' => ['lat' => -2.137, 'lng' => -79.894, 'address' => 'Origen', 'name' => null]]);
        $engine->respondTo($client->phone, $client, '[ubicacion]', ['type' => 'location', 'location' => ['lat' => -2.167, 'lng' => -79.900, 'address' => 'Destino', 'name' => null]]);
        $engine->respondTo($client->phone, $client, 'wa_pax:1');
        $engine->respondTo($client->phone, $client, 'wa_select_driver:'.$driver->id);

        $confirmMessage = collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0])
            ->last(fn ($request) => ($request['interactive']['body']['text'] ?? null) && str_contains($request['interactive']['body']['text'], 'Confirme su solicitud'));

        $this->assertStringNotContainsString('$', $confirmMessage['interactive']['body']['text']);
        $this->assertStringNotContainsString('Costo aproximado', $confirmMessage['interactive']['body']['text']);
    }

    /**
     * Pedido explícito del usuario: "una vez se genere la solicitud podrias
     * agregar un boton de cancelar solicitud" y "si no ha recibido ninguna
     * repuesta en unos 30 segundos... que indique que se esta buscando un
     * conductor".
     */
    public function test_creating_a_request_offers_a_cancel_button_and_schedules_a_still_searching_check(): void
    {
        Bus::fake();
        WhatsAppSetting::current()->update(['client_ride_booking_enabled' => true]);
        Config::set('services.whatsapp.token', 'fake-token');
        Config::set('services.whatsapp.phone_number_id', '123456');
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.fake']]], 200)]);
        $client = User::factory()->create(['phone' => '+593991112204', 'whatsapp_privacy_accepted_at' => now()]);
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['is_available' => true, 'current_lat' => -2.138, 'current_lng' => -79.895, 'passenger_capacity' => 4]);
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        $engine = app(ChatbotEngine::class);
        $engine->respondTo($client->phone, $client, 'pedir carrera');
        $engine->respondTo($client->phone, $client, 'wa_when_now');
        $engine->respondTo($client->phone, $client, '[ubicacion]', ['type' => 'location', 'location' => ['lat' => -2.137, 'lng' => -79.894, 'address' => 'Origen', 'name' => null]]);
        $engine->respondTo($client->phone, $client, '[ubicacion]', ['type' => 'location', 'location' => ['lat' => -2.167, 'lng' => -79.900, 'address' => 'Destino', 'name' => null]]);
        $engine->respondTo($client->phone, $client, 'wa_pax:1');
        $engine->respondTo($client->phone, $client, 'wa_select_driver:'.$driver->id);
        $engine->respondTo($client->phone, $client, 'wa_booking_confirm');

        $rideRequest = RideRequest::where('client_user_id', $client->id)->latest('id')->firstOrFail();

        $successMessage = collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0])
            ->last(fn ($request) => ($request['interactive']['body']['text'] ?? null) && str_contains($request['interactive']['body']['text'], 'Solicitud #'));
        $this->assertSame(
            ['wa_pending_cancel'],
            collect($successMessage['interactive']['action']['buttons'])->pluck('reply.id')->all()
        );

        Bus::assertDispatched(NotifyWhatsAppStillSearchingForDriver::class, fn ($job) => $job->rideRequestId === $rideRequest->id);
    }

    /**
     * Pedido explícito del usuario: "si realmente no se encontro que la
     * misma plataforma indique y le mande un boton que diga pedir
     * nuevamente y que intente nuevamente con esos mismo parametros."
     */
    public function test_an_expired_request_offers_a_retry_button_that_recreates_the_same_request(): void
    {
        WhatsAppSetting::current()->update(['client_ride_booking_enabled' => true]);
        Config::set('services.whatsapp.token', 'fake-token');
        Config::set('services.whatsapp.phone_number_id', '123456');
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.fake']]], 200)]);
        $client = User::factory()->create(['phone' => '+593991112205', 'whatsapp_privacy_accepted_at' => now()]);
        // notifyWhatsAppExpired() solo avisa a quien tiene sesión activa de
        // WhatsApp (mismo criterio que el resto de avisos puntuales).
        WhatsAppSession::query()->create(['user_id' => $client->id, 'opened_at' => now(), 'expires_at' => now()->addHours(20)]);
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['is_available' => true, 'current_lat' => -2.138, 'current_lng' => -79.895, 'passenger_capacity' => 4]);
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        $engine = app(ChatbotEngine::class);
        $engine->respondTo($client->phone, $client, 'pedir carrera');
        $engine->respondTo($client->phone, $client, 'wa_when_now');
        $engine->respondTo($client->phone, $client, '[ubicacion]', ['type' => 'location', 'location' => ['lat' => -2.137, 'lng' => -79.894, 'address' => 'Origen', 'name' => null]]);
        $engine->respondTo($client->phone, $client, '[ubicacion]', ['type' => 'location', 'location' => ['lat' => -2.167, 'lng' => -79.900, 'address' => 'Destino', 'name' => null]]);
        $engine->respondTo($client->phone, $client, 'wa_pax:1');
        $engine->respondTo($client->phone, $client, 'wa_select_driver:'.$driver->id);
        $engine->respondTo($client->phone, $client, 'wa_booking_confirm');

        $rideRequest = RideRequest::where('client_user_id', $client->id)->latest('id')->firstOrFail();

        // Fuerza el vencimiento: nadie más en la bolsa, expira de una.
        RideDispatchAdvancer::advanceOrExpire($rideRequest->id, $rideRequest->driver_user_id);
        $this->assertSame('expired', $rideRequest->fresh()->status);

        $retryMessage = collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0])
            ->last(fn ($request) => ($request['interactive']['action']['buttons'][0]['reply']['id'] ?? null) === 'wa_retry_request:'.$rideRequest->id);
        $this->assertNotNull($retryMessage);

        $engine->respondTo($client->phone, $client, 'wa_retry_request:'.$rideRequest->id);

        // Con QUEUE_CONNECTION=sync el job de vencimiento de la NUEVA
        // solicitud también corre de inmediato (mismo comportamiento ya
        // señalado en otros tests de este archivo) — lo que importa acá es
        // que el reintento sí creó una solicitud nueva con los mismos
        // parámetros, no que haya quedado "pending" en este entorno de test.
        $this->assertSame(2, RideRequest::where('client_user_id', $client->id)->count());
        $retryRequest = RideRequest::where('client_user_id', $client->id)->latest('id')->firstOrFail();
        $this->assertNotSame($rideRequest->id, $retryRequest->id);
        $this->assertSame('Origen', $retryRequest->origin_address);
        $this->assertSame('Destino', $retryRequest->destination_address);
        $this->assertSame($driver->id, $retryRequest->driver_user_id);
    }
}
