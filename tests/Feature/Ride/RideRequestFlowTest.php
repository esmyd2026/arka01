<?php

namespace Tests\Feature\Ride;

use App\Events\RideArrived;
use App\Events\RideCancelled;
use App\Events\RideCompleted;
use App\Events\RidePickedUp;
use App\Events\RideRequestDeclined;
use App\Jobs\ExpireRideOffer;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\PricingSetting;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\Sector;
use App\Models\User;
use App\Notifications\RideArrivedPushNotification;
use App\Notifications\RideCancelledPushNotification;
use App\Notifications\RidePickedUpPushNotification;
use App\Notifications\RideRequestDeclinedPushNotification;
use App\Services\PriceCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Cubre la Fase 2 del roadmap: pedir una carrera (a un conductor puntual o a
 * toda la flota), aceptarla, y el ciclo de completarla y pagarla.
 */
class RideRequestFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Mediodía fijo para que el cálculo de precio (sin recargo nocturno,
        // sección 5) sea siempre el mismo en las aserciones.
        Carbon::setTestNow(Carbon::parse('2026-01-15 12:00:00'));
    }

    /**
     * Cliente con su flota (con al menos un conductor activo) y ese conductor,
     * listos para pedirle una carrera. Reutilizado por varios tests.
     */
    private function clientWithFleetDriver(): array
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['rate_per_km' => 0.50, 'is_available' => true]);

        $fleet = Fleet::factory()->for($client, 'owner')->create();
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        return [$client, $driver, $fleet];
    }

    /**
     * Regresión: "/flota/{fleet}" (ruta comodín) está registrada antes que
     * "/flota/solicitar" en routes/web.php — si el orden se invierte, un GET
     * acá cae en fleet.show con {fleet}="solicitar" y devuelve 404 en vez de
     * mostrar el formulario de pedir carrera.
     */
    public function test_the_request_screen_loads_and_is_not_swallowed_by_the_fleet_show_route(): void
    {
        [$client] = $this->clientWithFleetDriver();

        $this->actingAs($client)->get(route('ride-requests.create'))->assertOk();
    }

    /**
     * Pedido explícito del usuario ("quitemos ese botón"): un conductor no
     * puede pedir una carrera — cada cuenta es cliente o conductor, nunca las
     * dos (sección 3.1). Sin este chequeo, entrar por URL directa le
     * terminaba provisionando una flota propia solo por pisar la pantalla
     * (resolveFleet() la crea sola si no existe).
     */
    public function test_a_driver_cannot_open_the_request_screen(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $this->actingAs($driver)->get(route('ride-requests.create'))->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('fleets', ['owner_user_id' => $driver->id]);
    }

    public function test_a_driver_cannot_submit_a_ride_request(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $this->actingAs($driver)->post(route('ride-requests.store'), [
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
        ])->assertSessionHasErrors('driver_user_id');

        $this->assertDatabaseCount('ride_requests', 0);
    }

    /**
     * Pedido explícito del usuario: elegir un conductor (desde "Mi flota", el
     * directorio o su perfil) tiene que arrancar la pantalla de pedir carrera
     * con ESE conductor ya elegido, no "toda la flota disponible".
     */
    public function test_choosing_a_driver_beforehand_preselects_them_on_the_request_screen(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $response = $this->actingAs($client)->get(route('ride-requests.create', ['conductor' => $driver->id]));

        $response->assertInertia(fn ($page) => $page->where('preselectedDriverId', $driver->id));
    }

    /**
     * El backend pasa el id tal cual llegue (validarlo contra quién es
     * seleccionable de verdad es responsabilidad del frontend, que ya tiene
     * `fleetDrivers`/`publicDrivers` cargados) — sin el query param, no hay
     * preselección.
     */
    public function test_no_driver_is_preselected_without_the_query_param(): void
    {
        [$client] = $this->clientWithFleetDriver();

        $response = $this->actingAs($client)->get(route('ride-requests.create'));

        $response->assertInertia(fn ($page) => $page->where('preselectedDriverId', null));
    }

    /**
     * Fix reportado por el usuario: el frontend calculaba el estimado sin
     * saber que existe una tarifa mínima — necesita este valor para poder
     * replicar el mismo max(distancia×tarifa, mínimo) que ya usa el backend
     * (PriceCalculator) y no mostrar un desglose por km engañoso.
     */
    public function test_the_request_screen_exposes_the_configured_minimum_fare(): void
    {
        [$client] = $this->clientWithFleetDriver();

        PricingSetting::current()->update(['minimum_fare' => 2.00]);

        $response = $this->actingAs($client)->get(route('ride-requests.create'));

        $response->assertInertia(fn ($page) => $page->where('minimumFare', fn ($value) => (float) $value === 2.0));
    }

    /**
     * Rediseño UX (pedido explícito del usuario, guiado por
     * ARKA01_Rediseno_UX_Flujo_Carreras.md): si se llega desde el buscador
     * "¿A dónde vas?" de Inicio, el destino ya viene elegido — la pantalla
     * tiene que recibirlo pre-llenado para arrancar directo en "Elige tu
     * conductor" sin volver a pedirlo.
     */
    public function test_the_request_screen_exposes_a_prefilled_destination_from_query_params(): void
    {
        [$client] = $this->clientWithFleetDriver();

        $response = $this->actingAs($client)->get(route('ride-requests.create', [
            'destination_lat' => -2.15,
            'destination_lng' => -79.90,
            'destination_address' => 'Mall del Sol',
        ]));

        $response->assertInertia(fn ($page) => $page
            ->where('initialDestination.lat', -2.15)
            ->where('initialDestination.lng', -79.90)
            ->where('initialDestination.address', 'Mall del Sol')
        );
    }

    public function test_the_request_screen_has_no_prefilled_destination_without_query_params(): void
    {
        [$client] = $this->clientWithFleetDriver();

        $response = $this->actingAs($client)->get(route('ride-requests.create'));

        $response->assertInertia(fn ($page) => $page->where('initialDestination', null));
    }

    /**
     * Documento formal de ajuste UX (sección 13): si el buscador de Inicio
     * ya sabía la ubicación en vivo del cliente, se manda de una vez como
     * origen — esta pantalla no debería volver a pedir geolocalización para
     * algo que ya se resolvió.
     */
    public function test_the_request_screen_exposes_a_prefilled_origin_from_query_params(): void
    {
        [$client] = $this->clientWithFleetDriver();

        $response = $this->actingAs($client)->get(route('ride-requests.create', [
            'origin_lat' => -2.14,
            'origin_lng' => -79.89,
            'origin_address' => 'Mi ubicación',
        ]));

        $response->assertInertia(fn ($page) => $page
            ->where('initialOrigin.lat', -2.14)
            ->where('initialOrigin.lng', -79.89)
            ->where('initialOrigin.address', 'Mi ubicación')
        );
    }

    public function test_the_request_screen_has_no_prefilled_origin_without_query_params(): void
    {
        [$client] = $this->clientWithFleetDriver();

        $response = $this->actingAs($client)->get(route('ride-requests.create'));

        $response->assertInertia(fn ($page) => $page->where('initialOrigin', null));
    }

    /**
     * Pedido explícito del usuario: "guardá las que ya ha realizado para que
     * aparezcan como favoritas" — direcciones que el cliente ya usó antes
     * (de origen o de destino), la más repetida primero.
     */
    public function test_the_request_screen_exposes_frequently_used_places(): void
    {
        [$client, $driver, $fleet] = $this->clientWithFleetDriver();

        // "Casa" se repite dos veces (una como origen, otra como destino) —
        // tiene que salir primero. "Trabajo" una sola vez.
        RideRequest::factory()->create([
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'origin_address' => 'Casa',
            'origin_lat' => -2.15,
            'origin_lng' => -79.90,
            'destination_address' => 'Trabajo',
            'destination_lat' => -2.20,
            'destination_lng' => -79.88,
            'status' => 'accepted',
        ]);

        RideRequest::factory()->create([
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'origin_address' => 'Trabajo',
            'origin_lat' => -2.20,
            'origin_lng' => -79.88,
            'destination_address' => 'Casa',
            'destination_lat' => -2.15,
            'destination_lng' => -79.90,
            'status' => 'accepted',
        ]);

        // Un tercer viaje solo de "Casa" a un lugar sin nombre, para que
        // quede con más repeticiones que "Trabajo" y el orden sea inequívoco.
        RideRequest::factory()->create([
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'origin_address' => 'Casa',
            'origin_lat' => -2.15,
            'origin_lng' => -79.90,
            'destination_address' => null,
            'status' => 'accepted',
        ]);

        $response = $this->actingAs($client)->get(route('ride-requests.create'));

        $response->assertInertia(fn ($page) => $page
            ->where('frequentPlaces.0.address', 'Casa')
            ->where('frequentPlaces.0.count', 3)
            ->where('frequentPlaces.1.address', 'Trabajo')
            ->where('frequentPlaces.1.count', 2)
            ->has('frequentPlaces', 2)
        );
    }

    /**
     * No tiene sentido mostrar una "dirección frecuente" en blanco cuando el
     * cliente no escribió ninguna referencia — solo dejó el mapa/sector.
     */
    public function test_requests_without_an_address_do_not_pollute_frequent_places(): void
    {
        [$client, $driver, $fleet] = $this->clientWithFleetDriver();

        RideRequest::factory()->create([
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'origin_address' => null,
            'destination_address' => null,
            'status' => 'accepted',
        ]);

        $response = $this->actingAs($client)->get(route('ride-requests.create'));

        $response->assertInertia(fn ($page) => $page->has('frequentPlaces', 0));
    }

    /**
     * Feedback tipo Uber (consideración agregada al alcance): si nadie
     * respondió todavía, el cliente puede subir su propia oferta.
     */
    public function test_client_can_raise_their_own_offer_while_pending(): void
    {
        [$client, $driver, $fleet] = $this->clientWithFleetDriver();

        $rideRequest = RideRequest::factory()->create([
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'pending',
            'current_offered_price' => 5,
        ]);

        $this->actingAs($client)
            ->post(route('ride-requests.raise-offer', $rideRequest), ['offered_amount' => 7])
            ->assertRedirect();

        $this->assertSame('7.00', $rideRequest->fresh()->current_offered_price);
    }

    public function test_cannot_raise_the_offer_below_the_current_amount(): void
    {
        [$client, $driver, $fleet] = $this->clientWithFleetDriver();

        $rideRequest = RideRequest::factory()->create([
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'pending',
            'current_offered_price' => 5,
        ]);

        $this->actingAs($client)
            ->post(route('ride-requests.raise-offer', $rideRequest), ['offered_amount' => 3])
            ->assertSessionHasErrors('offered_amount');
    }

    public function test_client_can_request_a_ride_to_a_specific_driver(): void
    {
        [$client, $driver, $fleet] = $this->clientWithFleetDriver();

        $response = $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => $driver->id,
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('ride_requests', [
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'pending',
        ]);
    }

    /**
     * Bug real confirmado por el usuario ("probá el mapa... en temas de
     * km"): la línea recta (Haversine) podía dar bastante menos que la
     * distancia real de manejo — ahora se usa la distancia real que manda
     * el frontend (misma ruta que ya se ve en el mapa, OSRM) en vez de la
     * línea recta, siempre que sea razonable.
     */
    public function test_a_reasonable_route_distance_is_used_instead_of_the_straight_line(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        // La línea recta entre estas dos coordenadas da ~4.17 km — 5.5 km
        // de ruta real es perfectamente razonable para una calle con curvas.
        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => $driver->id,
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
            'route_distance_km' => 5.5,
        ]);

        $this->assertSame(5.5, (float) RideRequest::firstOrFail()->distance_km);
    }

    public function test_a_route_distance_shorter_than_the_straight_line_is_ignored(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        // Ninguna ruta real puede ser MÁS CORTA que la línea recta (~4.17 km
        // acá) — un valor menor es señal de un dato raro, no de confiar.
        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => $driver->id,
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
            'route_distance_km' => 2.0,
        ]);

        $this->assertSame(4.17, (float) RideRequest::firstOrFail()->distance_km);
    }

    public function test_an_absurdly_large_route_distance_is_ignored(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => $driver->id,
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
            'route_distance_km' => 500,
        ]);

        $this->assertSame(4.17, (float) RideRequest::firstOrFail()->distance_km);
    }

    /**
     * Forma de pago (pedido explícito del usuario): el cliente todavía no
     * podía elegirla — "efectivo" queda de default si no manda nada.
     */
    public function test_a_ride_request_defaults_to_cash_payment(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => $driver->id,
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
        ])->assertRedirect();

        $this->assertDatabaseHas('ride_requests', ['client_user_id' => $client->id, 'payment_method' => 'efectivo']);
    }

    /**
     * El cliente puede elegir transferencia, y queda copiada a la carrera
     * confirmada al aceptar (mismo patrón que rate_per_km_snapshot/price,
     * ver RideRequestController::accept()).
     */
    public function test_the_chosen_payment_method_is_saved_and_copied_to_the_confirmed_ride(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => $driver->id,
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
            'payment_method' => 'transferencia',
        ])->assertRedirect();

        $rideRequest = RideRequest::where('client_user_id', $client->id)->firstOrFail();
        $this->assertSame('transferencia', $rideRequest->payment_method);

        $this->actingAs($driver)->post(route('ride-requests.accept', $rideRequest))->assertRedirect();

        $this->assertDatabaseHas('rides', ['ride_request_id' => $rideRequest->id, 'payment_method' => 'transferencia']);
    }

    /**
     * Despacho secuencial estilo Uber (pedido explícito del usuario): "toda
     * la flota" ya no manda la solicitud a todos a la vez con driver_user_id
     * en null — se le ofrece de una al primer candidato (acá, el único que
     * hay), con dispatch_pool guardado y un cronómetro de 30 seg. armado
     * para la cascada. Queue::fake() evita que ese Job corra de una bajo
     * QUEUE_CONNECTION=sync (forzado en phpunit.xml) y adelante la cascada
     * dentro del mismo test.
     */
    public function test_client_can_request_a_ride_to_the_whole_fleet(): void
    {
        Queue::fake();
        [$client, $driver] = $this->clientWithFleetDriver();

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => null,
            'dispatch_pool' => 'fleet',
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
        ])->assertRedirect();

        $this->assertDatabaseHas('ride_requests', [
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'dispatch_pool' => 'fleet',
            'status' => 'pending',
        ]);

        Queue::assertPushed(ExpireRideOffer::class);
    }

    /**
     * Zonas del Ecuador (consideración agregada al alcance): además del mapa,
     * el cliente indica el sector de origen/destino — ej. "Sauces 1" → "Samanes 3".
     */
    public function test_client_can_request_a_ride_with_origin_and_destination_sectors(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();
        $sauces = Sector::query()->where('name', 'Sauces 1')->firstOrFail();
        $samanes = Sector::query()->where('name', 'Samanes 3')->firstOrFail();

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => $driver->id,
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'origin_sector_id' => $sauces->id,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
            'destination_sector_id' => $samanes->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('ride_requests', [
            'client_user_id' => $client->id,
            'origin_sector_id' => $sauces->id,
            'destination_sector_id' => $samanes->id,
        ]);
    }

    /**
     * Regresión reportada por el usuario: pedir "ahora mismo" quedaba
     * bloqueado con un error de formato de fecha. Causa real: el formulario
     * manda `scheduled_date`/`scheduled_time` como '' (string vacío, no
     * ausentes) en modo "ahora mismo" — sin 'nullable' en la validación,
     * `date_format` los rechazaba igual aunque `required_if` no los exigiera.
     */
    public function test_requesting_a_ride_for_right_now_works_even_though_the_form_sends_empty_schedule_fields(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => $driver->id,
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
            'is_scheduled' => false,
            'scheduled_date' => '',
            'scheduled_time' => '',
            'round_trip' => false,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('ride_requests', [
            'client_user_id' => $client->id,
            'is_scheduled' => false,
            'scheduled_at' => null,
        ]);
    }

    public function test_an_active_immediate_request_blocks_another_immediate_request_and_other_pages(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();
        $payload = [
            'driver_user_id' => $driver->id,
            'origin_lat' => -0.1807, 'origin_lng' => -78.4678,
            'destination_lat' => -0.2000, 'destination_lng' => -78.5000,
            'is_scheduled' => false,
        ];

        $this->actingAs($client)->post(route('ride-requests.store'), $payload)->assertSessionHasNoErrors();
        $this->actingAs($client)->post(route('ride-requests.store'), $payload)->assertSessionHasErrors('ride');
        $this->actingAs($client)->get(route('profile.edit'))->assertRedirect(route('rides.index'));
        $this->assertDatabaseCount('ride_requests', 1);
    }

    public function test_an_active_immediate_request_does_not_block_a_future_scheduled_request(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();
        $base = [
            'driver_user_id' => $driver->id,
            'origin_lat' => -0.1807, 'origin_lng' => -78.4678,
            'destination_lat' => -0.2000, 'destination_lng' => -78.5000,
        ];

        $this->actingAs($client)->post(route('ride-requests.store'), [...$base, 'is_scheduled' => false]);
        $this->actingAs($client)->post(route('ride-requests.store'), [
            ...$base,
            'is_scheduled' => true,
            'scheduled_date' => '2026-01-16',
            'scheduled_time' => '08:00',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('ride_requests', 2);
    }

    /**
     * "Ahora mismo" (default) vs "programada" (consideración agregada al
     * alcance, pedido explícito del usuario): fecha/hora futura + ida y vuelta.
     */
    public function test_a_ride_request_can_be_scheduled_for_later_and_marked_round_trip(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => $driver->id,
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
            'is_scheduled' => true,
            'scheduled_date' => '2026-01-16',
            'scheduled_time' => '08:00',
            'round_trip' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('ride_requests', [
            'client_user_id' => $client->id,
            'is_scheduled' => true,
            'scheduled_at' => '2026-01-16 08:00:00',
            'round_trip' => true,
        ]);
    }

    /**
     * Bug real reportado por el usuario, grave: pedía las 06:40 y al
     * conductor le llegaba "01:40" en la tarjeta de "Programados" — 5 horas
     * menos, justo la diferencia entre UTC y America/Guayaquil. Causa real:
     * `config/app.php` tenía la zona horaria hardcodeada en 'UTC' sin leer
     * el `.env` (`APP_TIMEZONE=America/Guayaquil` nunca se aplicaba), así
     * que la hora local que tipeaba el cliente quedaba mal etiquetada como
     * UTC — al viajar hacia el conductor (serialización a ISO 8601 con "Z"
     * + `new Date().toLocaleString()` en el navegador) esa etiqueta mala se
     * traducía en una resta de 5 horas de más.
     */
    public function test_scheduled_time_is_interpreted_in_the_local_timezone_not_utc(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => $driver->id,
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
            'is_scheduled' => true,
            'scheduled_date' => '2026-01-16',
            'scheduled_time' => '06:40',
        ])->assertRedirect();

        $rideRequest = RideRequest::firstOrFail();

        // La hora local sigue siendo la que tipeó el cliente, sin correrse...
        $this->assertSame('06:40', $rideRequest->scheduled_at->format('H:i'));
        // ...y lo que de verdad viaja hacia el conductor (Inertia/broadcast
        // serializan a UTC con "Z") tiene que ser 5 horas más tarde, no la
        // misma hora con un "Z" pegado encima.
        $this->assertSame('2026-01-16T11:40:00.000000Z', $rideRequest->scheduled_at->clone()->utc()->toJSON());
    }

    public function test_scheduling_a_ride_in_the_past_is_rejected(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => $driver->id,
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
            'is_scheduled' => true,
            'scheduled_date' => '2026-01-15',
            'scheduled_time' => '08:00',
        ])->assertSessionHasErrors('scheduled_time');
    }

    /**
     * Pedido explícito del usuario: "en el futuro" a secas dejaba programar
     * para dentro de unos minutos — ahora exige un mínimo de 2 horas de
     * anticipación, incluso programando para el mismo día.
     */
    public function test_scheduling_less_than_two_hours_ahead_is_rejected(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        // "Ahora" en el test es 2026-01-15 12:00:00 — 13:00 es solo 1 hora después.
        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => $driver->id,
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
            'is_scheduled' => true,
            'scheduled_date' => '2026-01-15',
            'scheduled_time' => '13:00',
        ])->assertSessionHasErrors('scheduled_time');
    }

    public function test_scheduling_exactly_two_hours_ahead_is_allowed(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => $driver->id,
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
            'is_scheduled' => true,
            'scheduled_date' => '2026-01-15',
            'scheduled_time' => '14:00',
        ])->assertSessionHasNoErrors();
    }

    /**
     * Pedido explícito del usuario: no se le puede pisar a un conductor
     * puntual un horario que ya tiene comprometido con otra carrera
     * programada YA ACEPTADA (no una que todavía nadie tomó).
     */
    public function test_cannot_schedule_a_specific_driver_who_already_has_a_nearby_committed_ride(): void
    {
        [$client, $driver, $fleet] = $this->clientWithFleetDriver();

        $existingRequest = RideRequest::factory()->create([
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'is_scheduled' => true,
            'scheduled_at' => '2026-01-16 08:00:00',
            'status' => 'accepted',
        ]);
        Ride::factory()->create([
            'ride_request_id' => $existingRequest->id,
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'scheduled',
            'started_at' => null,
        ]);

        // 30 minutos después del compromiso ya aceptado — cae dentro de la
        // ventana de una hora antes/después.
        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => $driver->id,
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
            'is_scheduled' => true,
            'scheduled_date' => '2026-01-16',
            'scheduled_time' => '08:30',
        ])->assertSessionHasErrors('scheduled_time');
    }

    public function test_can_schedule_a_specific_driver_far_enough_from_their_committed_ride(): void
    {
        [$client, $driver, $fleet] = $this->clientWithFleetDriver();

        $existingRequest = RideRequest::factory()->create([
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'is_scheduled' => true,
            'scheduled_at' => '2026-01-16 08:00:00',
            'status' => 'accepted',
        ]);
        Ride::factory()->create([
            'ride_request_id' => $existingRequest->id,
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'scheduled',
            'started_at' => null,
        ]);

        // 3 horas después — bien afuera de la ventana de conflicto.
        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => $driver->id,
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
            'is_scheduled' => true,
            'scheduled_date' => '2026-01-16',
            'scheduled_time' => '11:00',
        ])->assertSessionHasNoErrors();
    }

    /**
     * Pedido explícito del usuario: un campo de observación libre para el
     * cliente al pedir la carrera, nunca obligatorio.
     */
    public function test_a_ride_request_can_include_an_optional_note(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => $driver->id,
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
            'notes' => 'El portón es el azul.',
        ])->assertRedirect();

        $this->assertSame('El portón es el azul.', RideRequest::firstOrFail()->notes);
    }

    public function test_a_ride_request_without_a_note_is_still_accepted(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => $driver->id,
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
        ])->assertSessionHasNoErrors();

        $this->assertNull(RideRequest::firstOrFail()->notes);
    }

    /**
     * La observación se copia a la carrera aceptada — el conductor la sigue
     * teniendo a mano durante todo el viaje, no solo al decidir si aceptar.
     */
    public function test_the_note_is_copied_to_the_ride_when_accepted(): void
    {
        [$client, $driver, $fleet] = $this->clientWithFleetDriver();

        $rideRequest = RideRequest::factory()->create([
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'pending',
            'notes' => 'Llamar al llegar.',
        ]);

        $this->actingAs($driver)->post(route('ride-requests.accept', $rideRequest))->assertRedirect();

        $this->assertSame('Llamar al llegar.', Ride::where('ride_request_id', $rideRequest->id)->firstOrFail()->notes);
    }

    /**
     * El punto central de 'scheduled' (consideración agregada al alcance):
     * aceptar una solicitud programada NO puede dejar al conductor "ocupado"
     * desde ya — recién lo está cuando arranca de verdad (RideController::start()).
     */
    public function test_accepting_a_scheduled_request_creates_a_ride_that_does_not_start_yet(): void
    {
        [$client, $driver, $fleet] = $this->clientWithFleetDriver();

        $rideRequest = RideRequest::factory()->create([
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'pending',
            'is_scheduled' => true,
            'scheduled_at' => '2026-01-16 08:00:00',
            'round_trip' => true,
        ]);

        $this->actingAs($driver)->post(route('ride-requests.accept', $rideRequest))->assertRedirect();

        $this->assertDatabaseHas('rides', [
            'ride_request_id' => $rideRequest->id,
            'status' => 'scheduled',
            'started_at' => null,
            'round_trip' => true,
        ]);
    }

    public function test_driver_can_start_a_scheduled_ride(): void
    {
        [$client, $driver, $fleet] = $this->clientWithFleetDriver();

        $ride = Ride::factory()->create([
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'scheduled',
            'started_at' => null,
        ]);

        $this->actingAs($driver)->post(route('rides.start', $ride))->assertRedirect();

        $ride->refresh();
        $this->assertSame('in_progress', $ride->status);
        $this->assertNotNull($ride->started_at);
    }

    public function test_the_client_cannot_start_a_scheduled_ride(): void
    {
        [$client, $driver, $fleet] = $this->clientWithFleetDriver();

        $ride = Ride::factory()->create([
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'scheduled',
            'started_at' => null,
        ]);

        $this->actingAs($client)->post(route('rides.start', $ride))->assertForbidden();
    }

    public function test_cannot_start_a_ride_that_is_already_in_progress(): void
    {
        [$client, $driver, $fleet] = $this->clientWithFleetDriver();

        $ride = Ride::factory()->create([
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'in_progress',
        ]);

        $this->actingAs($driver)->post(route('rides.start', $ride))->assertSessionHasErrors('ride');
    }

    /**
     * Pedido explícito del usuario: el conductor marca "ya llegué" al punto
     * de encuentro y el cliente se entera en vivo (push + WebSocket).
     */
    public function test_driver_can_mark_arrival_and_the_client_is_notified(): void
    {
        Event::fake([RideArrived::class]);
        Notification::fake();

        [$client, $driver, $fleet] = $this->clientWithFleetDriver();

        $ride = Ride::factory()->create([
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'in_progress',
            'arrived_at' => null,
        ]);

        $this->actingAs($driver)->post(route('rides.arrived', $ride))->assertRedirect();

        $ride->refresh();
        $this->assertNotNull($ride->arrived_at);

        Event::assertDispatched(RideArrived::class);
        Notification::assertSentTo($client, RideArrivedPushNotification::class);
    }

    public function test_the_client_cannot_mark_arrival(): void
    {
        [$client, $driver, $fleet] = $this->clientWithFleetDriver();

        $ride = Ride::factory()->create([
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'in_progress',
        ]);

        $this->actingAs($client)->post(route('rides.arrived', $ride))->assertForbidden();
    }

    public function test_arrival_cannot_be_marked_twice(): void
    {
        [$client, $driver, $fleet] = $this->clientWithFleetDriver();

        $ride = Ride::factory()->create([
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'in_progress',
            'arrived_at' => now(),
        ]);

        $this->actingAs($driver)->post(route('rides.arrived', $ride))->assertSessionHasErrors('ride');
    }

    /**
     * Pedido explícito del usuario: guardar la fecha y hora de cuándo el
     * conductor recogió al cliente de verdad, para calcular esa información
     * después (ej. tiempo de espera).
     */
    public function test_driver_can_mark_the_client_as_picked_up(): void
    {
        Event::fake([RidePickedUp::class]);
        Notification::fake();

        [$client, $driver, $fleet] = $this->clientWithFleetDriver();

        $ride = Ride::factory()->create([
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'in_progress',
            'arrived_at' => now(),
            'picked_up_at' => null,
        ]);

        $this->actingAs($driver)->post(route('rides.picked-up', $ride))->assertRedirect();

        $ride->refresh();
        $this->assertNotNull($ride->picked_up_at);

        Event::assertDispatched(RidePickedUp::class);
        Notification::assertSentTo($client, RidePickedUpPushNotification::class);
    }

    public function test_pickup_cannot_be_marked_twice(): void
    {
        [$client, $driver, $fleet] = $this->clientWithFleetDriver();

        $ride = Ride::factory()->create([
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'in_progress',
            'arrived_at' => now(),
            'picked_up_at' => now(),
        ]);

        $this->actingAs($driver)->post(route('rides.picked-up', $ride))->assertSessionHasErrors('ride');
    }

    /**
     * Pedido explícito del usuario: "validá que las acciones del
     * conductor... estén acorde a la ubicación de origen" — si el navegador
     * manda coordenadas a kilómetros del origen real, se bloquea la acción.
     */
    public function test_marking_arrival_is_blocked_when_the_driver_is_far_from_the_origin(): void
    {
        [$client, $driver, $fleet] = $this->clientWithFleetDriver();

        $ride = Ride::factory()->create([
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'in_progress',
            'arrived_at' => null,
            'origin_lat' => -2.1962,
            'origin_lng' => -79.8862,
        ]);

        // A varios kilómetros del origen (fuera de la tolerancia de 1.5 km).
        $this->actingAs($driver)
            ->post(route('rides.arrived', $ride), ['lat' => -2.1614, 'lng' => -79.8998])
            ->assertSessionHasErrors('ride');

        $this->assertNull($ride->fresh()->arrived_at);
    }

    /**
     * El mismo chequeo, pero dentro del margen de tolerancia (deriva normal
     * de GPS en ciudad) sí debe dejar pasar la acción.
     */
    public function test_marking_arrival_succeeds_when_the_driver_is_near_the_origin(): void
    {
        Event::fake([RideArrived::class]);
        Notification::fake();

        [$client, $driver, $fleet] = $this->clientWithFleetDriver();

        $ride = Ride::factory()->create([
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'in_progress',
            'arrived_at' => null,
            'origin_lat' => -2.1962,
            'origin_lng' => -79.8862,
        ]);

        // A pocos metros del origen (dentro de la tolerancia de 1.5 km).
        $this->actingAs($driver)
            ->post(route('rides.arrived', $ride), ['lat' => -2.1965, 'lng' => -79.8865])
            ->assertRedirect();

        $this->assertNotNull($ride->fresh()->arrived_at);
    }

    /**
     * Diseño deliberadamente permisivo (misma lógica que
     * DriverProfile::isWithinRangeOf() en el resto de la app): si el
     * navegador no manda coordenadas (permiso denegado, no soportado,
     * timeout), la acción NO se bloquea — nunca dejar a un conductor sin
     * poder avanzar una carrera real por un permiso que puede rechazar.
     */
    public function test_marking_arrival_is_not_blocked_when_no_coordinates_are_sent(): void
    {
        Event::fake([RideArrived::class]);
        Notification::fake();

        [$client, $driver, $fleet] = $this->clientWithFleetDriver();

        $ride = Ride::factory()->create([
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'in_progress',
            'arrived_at' => null,
            'origin_lat' => -2.1962,
            'origin_lng' => -79.8862,
        ]);

        $this->actingAs($driver)->post(route('rides.arrived', $ride))->assertRedirect();

        $this->assertNotNull($ride->fresh()->arrived_at);
    }

    public function test_marking_pickup_is_blocked_when_the_driver_is_far_from_the_origin(): void
    {
        [$client, $driver, $fleet] = $this->clientWithFleetDriver();

        $ride = Ride::factory()->create([
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'in_progress',
            'arrived_at' => now(),
            'picked_up_at' => null,
            'origin_lat' => -2.1962,
            'origin_lng' => -79.8862,
        ]);

        $this->actingAs($driver)
            ->post(route('rides.picked-up', $ride), ['lat' => -2.1614, 'lng' => -79.8998])
            ->assertSessionHasErrors('ride');

        $this->assertNull($ride->fresh()->picked_up_at);
    }

    public function test_accepting_a_ride_request_copies_the_sectors_into_the_ride(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();
        $sauces = Sector::query()->where('name', 'Sauces 1')->firstOrFail();
        $samanes = Sector::query()->where('name', 'Samanes 3')->firstOrFail();

        $rideRequest = RideRequest::factory()->create([
            'fleet_id' => Fleet::where('owner_user_id', $client->id)->first()->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'pending',
            'origin_sector_id' => $sauces->id,
            'destination_sector_id' => $samanes->id,
        ]);

        $this->actingAs($driver)->post(route('ride-requests.accept', $rideRequest))->assertRedirect();

        $this->assertDatabaseHas('rides', [
            'ride_request_id' => $rideRequest->id,
            'origin_sector_id' => $sauces->id,
            'destination_sector_id' => $samanes->id,
        ]);
    }

    public function test_driver_can_accept_and_a_ride_is_created_with_the_right_price(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        // Desde la Fase 4, el precio que se acepta es el que quedó vigente en
        // la negociación (current_offered_price) — se fija al crear la
        // solicitud, no se recalcula en el momento de aceptar.
        $expectedPrice = PriceCalculator::suggestedPrice(10.0, 0.50)['total'];

        $rideRequest = RideRequest::factory()->create([
            'fleet_id' => Fleet::where('owner_user_id', $client->id)->first()->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'distance_km' => 10,
            'status' => 'pending',
            'current_offered_price' => $expectedPrice,
        ]);

        $this->actingAs($driver)
            ->post(route('ride-requests.accept', $rideRequest))
            ->assertRedirect();

        $this->assertDatabaseHas('ride_requests', [
            'id' => $rideRequest->id,
            'status' => 'accepted',
            'accepted_by' => $driver->id,
        ]);

        $this->assertDatabaseHas('rides', [
            'ride_request_id' => $rideRequest->id,
            'driver_user_id' => $driver->id,
            'client_user_id' => $client->id,
            'price' => number_format($expectedPrice, 2, '.', ''),
            'status' => 'in_progress',
        ]);
    }

    public function test_only_the_first_driver_wins_a_whole_fleet_request(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();

        $driverA = User::factory()->create();
        DriverProfile::factory()->for($driverA)->create(['rate_per_km' => 0.5]);
        FleetMember::factory()->for($fleet)->for($driverA, 'driver')->create(['added_by' => $client->id]);

        $driverB = User::factory()->create();
        DriverProfile::factory()->for($driverB)->create(['rate_per_km' => 0.6]);
        FleetMember::factory()->for($fleet)->for($driverB, 'driver')->create(['added_by' => $client->id]);

        $rideRequest = RideRequest::factory()->create([
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => null,
            'status' => 'pending',
        ]);

        $this->actingAs($driverA)->post(route('ride-requests.accept', $rideRequest))->assertRedirect();

        // El segundo conductor llega tarde: la solicitud ya no está pendiente.
        $this->actingAs($driverB)
            ->post(route('ride-requests.accept', $rideRequest))
            ->assertSessionHasErrors('ride_request');

        $this->assertSame(1, Ride::where('ride_request_id', $rideRequest->id)->count());
        $this->assertDatabaseHas('rides', ['ride_request_id' => $rideRequest->id, 'driver_user_id' => $driverA->id]);
    }

    public function test_driver_can_reject_a_directed_request(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $rideRequest = RideRequest::factory()->create([
            'fleet_id' => Fleet::where('owner_user_id', $client->id)->first()->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'pending',
        ]);

        $this->actingAs($driver)
            ->post(route('ride-requests.reject', $rideRequest))
            ->assertRedirect();

        $this->assertDatabaseHas('ride_requests', ['id' => $rideRequest->id, 'status' => 'cancelled']);
        $this->assertDatabaseMissing('rides', ['ride_request_id' => $rideRequest->id]);
    }

    /**
     * Bug reportado por el usuario: el cliente le pedía la carrera a un
     * conductor puntual, ese conductor la rechazaba, y no había ningún
     * aviso — la tarjeta de "Esperando respuesta" se quedaba pegada en
     * silencio. `RideRequestCancelled` no sirve para esto (avisa al canal
     * del CONDUCTOR, no del cliente) — hace falta `RideRequestDeclined`.
     */
    public function test_rejecting_a_directed_request_notifies_the_client(): void
    {
        Event::fake([RideRequestDeclined::class]);
        Notification::fake();

        [$client, $driver] = $this->clientWithFleetDriver();

        $rideRequest = RideRequest::factory()->create([
            'fleet_id' => Fleet::where('owner_user_id', $client->id)->first()->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'pending',
        ]);

        $this->actingAs($driver)
            ->post(route('ride-requests.reject', $rideRequest))
            ->assertRedirect();

        Event::assertDispatched(RideRequestDeclined::class, fn ($event) => $event->rideRequest->id === $rideRequest->id
            && $event->driverName === $driver->name);
        Notification::assertSentTo($client, RideRequestDeclinedPushNotification::class);
    }

    public function test_client_can_cancel_a_pending_request(): void
    {
        [$client] = $this->clientWithFleetDriver();

        $rideRequest = RideRequest::factory()->create([
            'fleet_id' => Fleet::where('owner_user_id', $client->id)->first()->id,
            'client_user_id' => $client->id,
            'status' => 'pending',
        ]);

        $this->actingAs($client)
            ->post(route('ride-requests.cancel', $rideRequest))
            ->assertRedirect();

        $this->assertDatabaseHas('ride_requests', ['id' => $rideRequest->id, 'status' => 'cancelled']);
    }

    /**
     * Pedido explícito del usuario: la carrera la finaliza ÚNICAMENTE el
     * conductor — antes cualquiera de las dos partes podía.
     */
    public function test_only_the_driver_can_complete_a_ride(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $ride = Ride::factory()->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'in_progress',
        ]);

        $this->actingAs($driver)->post(route('rides.complete', $ride))->assertRedirect();
        $this->assertDatabaseHas('rides', ['id' => $ride->id, 'status' => 'completed']);
    }

    public function test_the_client_cannot_complete_a_ride(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $ride = Ride::factory()->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'in_progress',
        ]);

        $this->actingAs($client)->post(route('rides.complete', $ride))->assertForbidden();

        $this->assertSame('in_progress', $ride->fresh()->status);
    }

    /**
     * Pedido explícito del usuario: "...y la [acción] de terminar acorde al
     * destino" — completar la carrera exige estar cerca del destino, no del
     * origen.
     */
    public function test_completing_a_ride_is_blocked_when_the_driver_is_far_from_the_destination(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $ride = Ride::factory()->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'in_progress',
            'destination_lat' => -2.1614,
            'destination_lng' => -79.8998,
        ]);

        $this->actingAs($driver)
            ->post(route('rides.complete', $ride), ['lat' => -2.1962, 'lng' => -79.8862])
            ->assertSessionHasErrors('ride');

        $this->assertSame('in_progress', $ride->fresh()->status);
    }

    public function test_completing_a_ride_succeeds_when_the_driver_is_near_the_destination(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $ride = Ride::factory()->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'in_progress',
            'destination_lat' => -2.1614,
            'destination_lng' => -79.8998,
        ]);

        $this->actingAs($driver)
            ->post(route('rides.complete', $ride), ['lat' => -2.1616, 'lng' => -79.8999])
            ->assertRedirect();

        $this->assertSame('completed', $ride->fresh()->status);
    }

    /**
     * Pedido explícito del usuario: antes de esto no existía ninguna forma
     * de cancelar una carrera ya aceptada — el cliente quedaba sin salida
     * hasta que se completara, ni siquiera si el conductor todavía no había
     * arrancado. "Que eso también cuente para medir": por eso se verifica
     * `cancelled_at`, no solo el status.
     */
    public function test_client_can_cancel_an_accepted_ride_that_is_in_progress(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $ride = Ride::factory()->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'in_progress',
        ]);

        $this->actingAs($client)
            ->post(route('rides.cancel', $ride), ['reason' => 'Cambié de planes'])
            ->assertRedirect();

        $ride->refresh();
        $this->assertSame('cancelled', $ride->status);
        $this->assertNotNull($ride->cancelled_at);
        $this->assertSame('client', $ride->cancelled_by);
        $this->assertSame('Cambié de planes', $ride->cancellation_reason);
    }

    public function test_client_can_cancel_a_scheduled_ride_before_the_driver_starts_it(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $ride = Ride::factory()->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'scheduled',
            'started_at' => null,
        ]);

        $this->actingAs($client)
            ->post(route('rides.cancel', $ride), ['reason' => 'Cambié de planes'])
            ->assertRedirect();

        $this->assertSame('cancelled', $ride->fresh()->status);
    }

    /**
     * Pedido explícito del usuario: antes solo el cliente podía cancelar —
     * ahora el conductor también, con su propia lista de motivos, y a quien
     * NO canceló (el cliente, acá) le llega el aviso.
     */
    public function test_driver_can_cancel_a_ride_with_a_reason_and_an_optional_note(): void
    {
        Notification::fake();
        [$client, $driver] = $this->clientWithFleetDriver();

        $ride = Ride::factory()->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'in_progress',
        ]);

        $this->actingAs($driver)
            ->post(route('rides.cancel', $ride), [
                'reason' => 'Problema con el vehículo',
                'note' => 'Se pinchó una llanta.',
            ])
            ->assertRedirect();

        $ride->refresh();
        $this->assertSame('cancelled', $ride->status);
        $this->assertSame('driver', $ride->cancelled_by);
        $this->assertSame('Problema con el vehículo', $ride->cancellation_reason);
        $this->assertSame('Se pinchó una llanta.', $ride->cancellation_note);
        Notification::assertSentTo($client, RideCancelledPushNotification::class);
        Notification::assertNotSentTo($driver, RideCancelledPushNotification::class);
    }

    public function test_cancelling_without_a_note_is_allowed(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $ride = Ride::factory()->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'in_progress',
        ]);

        $this->actingAs($client)
            ->post(route('rides.cancel', $ride), ['reason' => 'Cambié de planes'])
            ->assertRedirect();

        $this->assertNull($ride->fresh()->cancellation_note);
    }

    public function test_cancelling_without_a_reason_is_rejected(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $ride = Ride::factory()->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'in_progress',
        ]);

        $this->actingAs($client)->post(route('rides.cancel', $ride))->assertSessionHasErrors('reason');
    }

    /**
     * Cada rol tiene su propia lista de motivos — un motivo del lado
     * conductor no es válido si lo manda el cliente, y viceversa.
     */
    public function test_a_reason_from_the_other_roles_list_is_rejected(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $ride = Ride::factory()->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'in_progress',
        ]);

        $this->actingAs($client)
            ->post(route('rides.cancel', $ride), ['reason' => 'Problema con el vehículo'])
            ->assertSessionHasErrors('reason');
    }

    public function test_a_stranger_cannot_cancel_a_ride(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();
        $stranger = User::factory()->create();

        $ride = Ride::factory()->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'in_progress',
        ]);

        $this->actingAs($stranger)->post(route('rides.cancel', $ride))->assertForbidden();

        $this->assertSame('in_progress', $ride->fresh()->status);
    }

    public function test_a_completed_ride_cannot_be_cancelled(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $ride = Ride::factory()->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $this->actingAs($client)
            ->post(route('rides.cancel', $ride), ['reason' => 'Cambié de planes'])
            ->assertSessionHasErrors('ride');
    }

    /**
     * Si el conductor ya iba en camino, tiene que enterarse — y quedar libre
     * de nuevo en su flota, mismo criterio que al completar una carrera.
     */
    public function test_cancelling_a_ride_notifies_and_frees_the_driver(): void
    {
        Event::fake([RideCancelled::class]);
        Notification::fake();
        [$client, $driver, $fleet] = $this->clientWithFleetDriver();

        $ride = Ride::factory()->create([
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'in_progress',
        ]);

        $this->actingAs($client)
            ->post(route('rides.cancel', $ride), ['reason' => 'Cambié de planes'])
            ->assertRedirect();

        Event::assertDispatched(RideCancelled::class, fn ($event) => $event->ride->id === $ride->id);
        Notification::assertSentTo($driver, RideCancelledPushNotification::class);
    }

    /**
     * Sin este aviso, el conductor seguía viéndose "en carrera" en "Mi
     * flota"/"¿A quién se la pedís?" aunque ya hubiese terminado el viaje
     * (consideración agregada al alcance, reportado por el usuario).
     */
    public function test_completing_a_ride_broadcasts_that_the_driver_is_free_again(): void
    {
        Event::fake([RideCompleted::class]);
        [$client, $driver, $fleet] = $this->clientWithFleetDriver();

        $ride = Ride::factory()->create([
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'in_progress',
        ]);

        $this->actingAs($driver)->post(route('rides.complete', $ride))->assertRedirect();

        Event::assertDispatched(RideCompleted::class, fn ($event) => $event->ride->id === $ride->id);
    }

    /**
     * Fidelización por puntos (pedido explícito del usuario): completar una
     * carrera corta desde la app suma 1 punto — ver
     * RideController::complete() y App\Models\DriverTier.
     */
    public function test_completing_a_short_ride_awards_one_point(): void
    {
        [$client, $driver, $fleet] = $this->clientWithFleetDriver();

        $ride = Ride::factory()->create([
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'in_progress',
            'distance_km' => 3,
        ]);

        $this->actingAs($driver)->post(route('rides.complete', $ride))->assertRedirect();

        $this->assertDatabaseHas('rides', ['id' => $ride->id, 'points_earned' => 1]);
        $this->assertSame(1, $driver->driverProfile->fresh()->total_points);
    }

    /**
     * Simétrico: desde 5 km (el corte de RideController::complete()) son 2 puntos.
     */
    public function test_completing_a_long_ride_awards_two_points(): void
    {
        [$client, $driver, $fleet] = $this->clientWithFleetDriver();

        $ride = Ride::factory()->create([
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'in_progress',
            'distance_km' => 8,
        ]);

        $this->actingAs($driver)->post(route('rides.complete', $ride))->assertRedirect();

        $this->assertDatabaseHas('rides', ['id' => $ride->id, 'points_earned' => 2]);
        $this->assertSame(2, $driver->driverProfile->fresh()->total_points);
    }

    /**
     * Los puntos se acumulan entre carreras, no se pisan (increment(), no
     * update() a un valor fijo).
     */
    public function test_points_accumulate_across_multiple_completed_rides(): void
    {
        [$client, $driver, $fleet] = $this->clientWithFleetDriver();

        foreach ([3, 8] as $distanceKm) {
            $ride = Ride::factory()->create([
                'fleet_id' => $fleet->id,
                'client_user_id' => $client->id,
                'driver_user_id' => $driver->id,
                'status' => 'in_progress',
                'distance_km' => $distanceKm,
            ]);

            $this->actingAs($driver)->post(route('rides.complete', $ride))->assertRedirect();
        }

        $this->assertSame(3, $driver->driverProfile->fresh()->total_points);
    }

    public function test_a_stranger_cannot_accept_a_request_for_a_fleet_they_do_not_belong_to(): void
    {
        [$client] = $this->clientWithFleetDriver();
        $stranger = User::factory()->create();
        DriverProfile::factory()->for($stranger)->create();

        $rideRequest = RideRequest::factory()->create([
            'fleet_id' => Fleet::where('owner_user_id', $client->id)->first()->id,
            'client_user_id' => $client->id,
            'driver_user_id' => null,
            'status' => 'pending',
        ]);

        $this->actingAs($stranger)
            ->post(route('ride-requests.accept', $rideRequest))
            ->assertForbidden();
    }
}
