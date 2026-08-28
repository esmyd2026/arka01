<?php

namespace Tests\Feature;

use App\Models\ClientCooperative;
use App\Models\Cooperative;
use App\Models\CooperativeDriverMembership;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetInvitation;
use App\Models\FleetMember;
use App\Models\Review;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\SavedRoute;
use App\Models\User;
use App\Models\WhatsAppSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * "Inicio" del cliente y del conductor (consideración agregada al alcance:
 * mockups provistos por el usuario) — avatares/tarjetas de "Mi flota" y
 * "Conductores cerca" con calificación y distancia, "Próximos viajes" con
 * datos reales (no fechas inventadas — no existe agendar a futuro), y para
 * el conductor el código de invitación y el sparkline de ganancias.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Rediseño UX (pedido explícito del usuario, guiado por
     * ARKA01_Rediseno_UX_Flujo_Carreras.md): el buscador "¿A dónde vas?" de
     * Inicio necesita las mismas direcciones favoritas y rutas guardadas que
     * ya usaba Ride/Request.vue — ver App\Services\FrequentPlaces, reusado
     * entre los dos controllers.
     */
    public function test_a_client_receives_frequent_places_and_saved_routes_for_the_home_search(): void
    {
        $client = User::factory()->create();
        Fleet::factory()->for($client, 'owner')->create();

        RideRequest::factory()->create([
            'client_user_id' => $client->id,
            // Solo alimenta lugares frecuentes: no debe representar una
            // solicitud inmediata todavía activa, porque el middleware de
            // seguridad redirige correctamente esas cuentas a Carreras.
            'status' => 'cancelled',
            'origin_address' => 'Casa',
            'origin_lat' => -2.15,
            'origin_lng' => -79.90,
            'destination_address' => 'Mall del Sol',
        ]);
        SavedRoute::factory()->create(['client_user_id' => $client->id, 'alias' => 'Trabajo']);

        $response = $this->actingAs($client)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->has('frequentPlaces', 2)
            ->has('savedRoutes', 1)
            ->where('savedRoutes.0.alias', 'Trabajo')
        );
    }

    /**
     * Bug real reportado por el usuario ("porque no centra la ubicación
     * actual"): sin esto, el mapa de Inicio arrancaba en el centro de Quito
     * por defecto (FleetMap.vue) hasta que la geolocalización en vivo del
     * navegador resolviera. Con una ubicación de registro ya guardada, el
     * mapa arranca centrado ahí de una, sin depender de ese permiso.
     */
    public function test_a_client_with_a_registration_location_receives_it_as_the_homes_initial_map_center(): void
    {
        $client = User::factory()->create(['registration_lat' => -2.1894, 'registration_lng' => -79.8890]);
        Fleet::factory()->for($client, 'owner')->create();

        $response = $this->actingAs($client)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('homeInitialCenter.lat', -2.1894)
            ->where('homeInitialCenter.lng', -79.8890)
        );
    }

    public function test_a_client_without_a_registration_location_receives_no_initial_map_center(): void
    {
        $client = User::factory()->create(['registration_lat' => null, 'registration_lng' => null]);
        Fleet::factory()->for($client, 'owner')->create();

        $response = $this->actingAs($client)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page->where('homeInitialCenter', null));
    }

    public function test_a_driver_receives_no_frequent_places_or_saved_routes(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $response = $this->actingAs($driver)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('frequentPlaces', [])
            ->where('savedRoutes', [])
        );
    }

    public function test_a_client_sees_their_fleet_drivers_with_the_right_status(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();

        $available = User::factory()->create();
        DriverProfile::factory()->for($available)->create(['is_available' => true]);
        FleetMember::factory()->for($fleet)->for($available, 'driver')->create(['added_by' => $client->id]);

        $busy = User::factory()->create();
        DriverProfile::factory()->for($busy)->create(['is_available' => true]);
        FleetMember::factory()->for($fleet)->for($busy, 'driver')->create(['added_by' => $client->id]);
        $otherClient = User::factory()->create();
        $otherFleet = Fleet::factory()->for($otherClient, 'owner')->create();
        $rideRequest = RideRequest::factory()->for($otherFleet)->create(['client_user_id' => $otherClient->id, 'driver_user_id' => $busy->id]);
        Ride::factory()->for($rideRequest, 'rideRequest')->for($otherFleet)->create([
            'client_user_id' => $otherClient->id,
            'driver_user_id' => $busy->id,
            'status' => 'in_progress',
        ]);

        $offline = User::factory()->create();
        DriverProfile::factory()->for($offline)->create(['is_available' => false]);
        FleetMember::factory()->for($fleet)->for($offline, 'driver')->create(['added_by' => $client->id]);

        $response = $this->actingAs($client)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('fleetDrivers', fn ($drivers) => collect($drivers)->firstWhere('user_id', $available->id)['status'] === 'available'
                && collect($drivers)->firstWhere('user_id', $busy->id)['status'] === 'busy'
                && collect($drivers)->firstWhere('user_id', $offline->id)['status'] === 'offline'
            )
        );
    }

    /**
     * Sección 19 del mockup del cliente: las tarjetas de "Mi flota" ahora
     * traen calificación y distancia, mismo criterio que "Conductores cerca".
     */
    public function test_fleet_drivers_include_rating_and_distance_when_location_is_shared(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();

        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create([
            'is_available' => true,
            'current_lat' => -0.1807,
            'current_lng' => -78.4678,
        ]);
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);
        Review::factory()->create(['reviewee_user_id' => $driver->id, 'rating' => 5]);

        $response = $this->actingAs($client)
            ->withSession(['dashboard_location' => [
                'lat' => -0.1807,
                'lng' => -78.4678,
                'captured_at' => now()->timestamp,
            ]])
            ->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('fleetDrivers.0.average_rating', fn ($rating) => (float) $rating === 5.0)
            ->where('fleetDrivers.0.review_count', 1)
            ->where('fleetDrivers.0.distance_km', fn ($distance) => $distance < 0.1)
        );
    }

    public function test_dashboard_location_is_stored_by_post_without_exposing_it_in_the_url(): void
    {
        $client = User::factory()->create();

        $response = $this->actingAs($client)->post(route('dashboard.location.update'), [
            'lat' => -2.137634581283383,
            'lng' => -79.8942474839653,
        ]);

        $response->assertNoContent();
        $response->assertSessionHas('dashboard_location.lat', fn ($lat) => abs($lat - (-2.137634581283383)) < 0.0000001);
        $response->assertSessionHas('dashboard_location.lng', fn ($lng) => abs($lng - (-79.8942474839653)) < 0.0000001);
    }

    public function test_legacy_dashboard_url_with_coordinates_is_immediately_cleaned(): void
    {
        $client = User::factory()->create();

        $response = $this->actingAs($client)->get(route('dashboard', [
            'lat' => -2.137634581283383,
            'lng' => -79.8942474839653,
        ]));

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('dashboard_location.lat', fn ($lat) => abs($lat - (-2.137634581283383)) < 0.0000001);
        $response->assertSessionHas('dashboard_location.lng', fn ($lng) => abs($lng - (-79.8942474839653)) < 0.0000001);
    }

    /**
     * Pedido explícito del usuario: "colocar todos los conductores sean de
     * su flota o cooperativas... o públicos" en el mapa de Inicio — antes
     * `nearbyDriversFor()` era una recomendación de "a quién sumar a tu
     * flota" y por eso EXCLUÍA a quien ya estaba en ella; ahora es un mapa
     * ilustrativo de quién está activo cerca, así que la flota propia SÍ
     * entra (se sigue excluyendo únicamente a la propia cuenta).
     */
    public function test_nearby_active_drivers_includes_fleet_cooperative_and_public_sources(): void
    {
        $client = User::factory()->create(['registration_lat' => -0.1807, 'registration_lng' => -78.4678]);
        $fleet = Fleet::factory()->for($client, 'owner')->create();

        $fleetDriver = User::factory()->create();
        DriverProfile::factory()->for($fleetDriver)->create([
            'is_available' => true, 'current_lat' => -0.181, 'current_lng' => -78.468,
        ]);
        FleetMember::factory()->for($fleet)->for($fleetDriver, 'driver')->create(['added_by' => $client->id]);

        $cooperative = Cooperative::query()->create(['user_id' => User::factory()->create()->id, 'name' => 'Coop de prueba']);
        $cooperative->forceFill(['status' => 'approved'])->save();
        ClientCooperative::query()->create(['client_user_id' => $client->id, 'cooperative_id' => $cooperative->id]);

        $cooperativeDriver = User::factory()->create();
        DriverProfile::factory()->for($cooperativeDriver)->create([
            'driver_type' => 'public_transport', 'is_available' => true, 'current_lat' => -0.182, 'current_lng' => -78.469,
        ]);
        CooperativeDriverMembership::query()->create([
            'cooperative_id' => $cooperative->id,
            'driver_user_id' => $cooperativeDriver->id,
            'invited_by_user_id' => $cooperative->user_id,
            'status' => 'accepted',
        ]);

        $publicDriver = User::factory()->create();
        DriverProfile::factory()->for($publicDriver)->create([
            'is_public' => true, 'is_available' => true, 'current_lat' => -0.183, 'current_lng' => -78.470,
        ]);

        $response = $this->actingAs($client)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('nearbyDrivers', function ($drivers) use ($fleetDriver, $cooperativeDriver, $publicDriver, $client) {
                $ids = collect($drivers)->pluck('user_id');

                return $ids->contains($fleetDriver->id)
                    && $ids->contains($cooperativeDriver->id)
                    && $ids->contains($publicDriver->id)
                    && ! $ids->contains($client->id);
            })
            ->where('nearbyDrivers.0.lat', fn ($lat) => $lat !== null)
            ->where('nearbyDrivers.0.source', fn ($source) => in_array($source, ['fleet', 'cooperative', 'public'], true))
        );
    }

    /**
     * "Solo los activos" (pedido explícito del usuario): ni un conductor
     * apagado ni uno ya en una carrera en curso deberían aparecer como un
     * pin más en el mapa de Inicio.
     */
    public function test_nearby_active_drivers_excludes_offline_or_busy_drivers(): void
    {
        $client = User::factory()->create(['registration_lat' => -0.1807, 'registration_lng' => -78.4678]);

        $offlineDriver = User::factory()->create();
        DriverProfile::factory()->for($offlineDriver)->create([
            'is_public' => true, 'is_available' => false, 'current_lat' => -0.181, 'current_lng' => -78.468,
        ]);

        $busyDriver = User::factory()->create();
        DriverProfile::factory()->for($busyDriver)->create([
            'is_public' => true, 'is_available' => true, 'current_lat' => -0.181, 'current_lng' => -78.468,
        ]);
        Ride::factory()->create(['driver_user_id' => $busyDriver->id, 'status' => 'in_progress']);

        $response = $this->actingAs($client)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('nearbyDrivers', fn ($drivers) => collect($drivers)->pluck('user_id')
                ->intersect([$offlineDriver->id, $busyDriver->id])->isEmpty()
            )
        );
    }

    /**
     * "Cerca del origen de la carrera" (pedido explícito del usuario): con
     * la ubicación del cliente conocida, un conductor a más de 15 km no
     * cuenta como "cerca" y no aparece en el mapa de Inicio.
     */
    public function test_nearby_active_drivers_are_limited_to_15km_when_the_clients_location_is_known(): void
    {
        $client = User::factory()->create(['registration_lat' => -0.1807, 'registration_lng' => -78.4678]);

        $farDriver = User::factory()->create();
        DriverProfile::factory()->for($farDriver)->create([
            // ~150 km al norte de Quito — bien fuera del radio de 15 km.
            'is_public' => true, 'is_available' => true, 'current_lat' => -1.5, 'current_lng' => -78.4678,
        ]);

        $response = $this->actingAs($client)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('nearbyDrivers', fn ($drivers) => ! collect($drivers)->contains('user_id', $farDriver->id))
        );
    }

    /**
     * Pedido explícito del usuario: "conductores... cerca de donde viven" —
     * si el navegador todavía no compartió ubicación en vivo esta vez
     * (recién entrando, antes del reload de onMounted), se usa la que dio
     * al registrarse.
     */
    public function test_nearby_active_drivers_falls_back_to_the_clients_registration_location(): void
    {
        $client = User::factory()->create([
            'registration_lat' => -0.1807,
            'registration_lng' => -78.4678,
        ]);

        $publicDriver = User::factory()->create();
        DriverProfile::factory()->for($publicDriver)->create([
            'is_public' => true,
            'is_available' => true,
            'current_lat' => -0.19,
            'current_lng' => -78.47,
        ]);

        // Sin ubicación temporal en sesión, como si el navegador todavía no
        // hubiera compartido ubicación en vivo esta vez.
        $response = $this->actingAs($client)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('nearbyDrivers.0.user_id', $publicDriver->id)
            ->where('nearbyDrivers.0.distance_km', fn ($km) => $km !== null)
        );
    }

    /**
     * Sin ninguna coordenada del cliente (ni en vivo ni de registro) no hay
     * "cerca" que calcular — se listan igual (mejor un pin sin certeza de
     * distancia que ninguno), solo que sin filtrar por radio y con
     * `distance_km` en null.
     */
    public function test_nearby_active_drivers_without_any_client_coordinates_are_not_filtered_by_distance(): void
    {
        $client = User::factory()->create(['registration_lat' => null, 'registration_lng' => null]);

        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create([
            'is_public' => true, 'is_available' => true, 'current_lat' => -1.5, 'current_lng' => -78.4678,
        ]);

        $response = $this->actingAs($client)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('nearbyDrivers.0.user_id', $driver->id)
            ->where('nearbyDrivers.0.distance_km', null)
        );
    }

    public function test_a_driver_does_not_see_the_client_home_sections(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $response = $this->actingAs($driver)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('driverStats.is_available', true)
            ->where('fleetDrivers', null)
            ->where('nearbyDrivers', null)
        );
    }

    /**
     * Bug reportado por el usuario ("Luis aparece desconectado si está en
     * línea"): `is_available` (prendió el switch) e "is_reachable" (además,
     * tiene un ping de ubicación reciente o WhatsApp abierto — mismo criterio
     * que ve el roster de sus clientes, DriverProfile::isReachable()) pueden
     * divergir. El propio Inicio del conductor necesita el segundo dato para
     * poder avisarle cuando está prendido pero invisible para sus clientes.
     */
    public function test_driver_stats_expose_whether_the_driver_is_actually_reachable(): void
    {
        $stale = User::factory()->create();
        DriverProfile::factory()->for($stale)->create([
            'is_available' => true,
            'location_updated_at' => now()->subMinutes(5),
        ]);

        $response = $this->actingAs($stale)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('driverStats.is_available', true)
            ->where('driverStats.is_reachable', false)
        );
    }

    public function test_driver_stats_report_reachable_with_a_recent_location_ping(): void
    {
        $fresh = User::factory()->create();
        DriverProfile::factory()->for($fresh)->create([
            'is_available' => true,
            'location_updated_at' => now(),
        ]);

        $response = $this->actingAs($fresh)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('driverStats.is_available', true)
            ->where('driverStats.is_reachable', true)
        );
    }

    /**
     * Se reportó: invitar a un conductor a la flota no le avisaba nada en su
     * Inicio (Dashboard.vue nunca escuchaba `.fleet-invitation.created`, solo
     * "Mis clientes de confianza" lo hacía, y solo si estaba parado ahí en
     * ese momento). Este número es lo que permite el badge + el aviso en vivo.
     */
    public function test_driver_stats_include_the_count_of_pending_fleet_invitations(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();

        FleetInvitation::query()->create([
            'fleet_id' => $fleet->id,
            'driver_user_id' => $driver->id,
            'invited_by' => $client->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($driver)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page->where('driverStats.pending_invitations', 1));
    }

    /**
     * `driverFleetIds` (consideración agregada al alcance: el inicio del
     * conductor tiene que poder suscribirse en vivo a las solicitudes "a
     * toda la flota", igual que ya hace Ride/Index.vue).
     */
    public function test_driver_receives_the_ids_of_the_fleets_they_belong_to(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        $response = $this->actingAs($driver)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('driverFleetIds', fn ($ids) => collect($ids)->contains($fleet->id))
        );
    }

    public function test_client_does_not_receive_driver_fleet_ids(): void
    {
        $client = User::factory()->create();
        Fleet::factory()->for($client, 'owner')->create();

        $response = $this->actingAs($client)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page->where('driverFleetIds', null));
    }

    /**
     * "Compartí tu código" y el sparkline de ganancias (mockup del conductor)
     * solo tienen sentido del lado conductor.
     */
    public function test_driver_receives_invite_code_and_a_14_day_earnings_sparkline(): void
    {
        $driver = User::factory()->create();
        $driverProfile = DriverProfile::factory()->for($driver)->create();

        $response = $this->actingAs($driver)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('inviteCode', $driverProfile->invite_code)
            ->where('earningsSparkline', fn ($sparkline) => count($sparkline) === 14)
        );
    }

    /**
     * Pedido explícito del usuario: "que le muestre también la cantidad de $
     * que ha hecho en el día y lo que lleva del mes" — earnings_today debe
     * sumar solo las carreras completadas hoy, sin arrastrar las de otros
     * días del mismo mes.
     */
    public function test_driver_stats_report_earnings_today_separately_from_the_month(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        Ride::factory()->create([
            'driver_user_id' => $driver->id,
            'status' => 'completed',
            'completed_at' => now(),
            'price' => 15,
        ]);
        Ride::factory()->create([
            'driver_user_id' => $driver->id,
            'status' => 'completed',
            'completed_at' => now()->subDays(5),
            'price' => 20,
        ]);

        $response = $this->actingAs($driver)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('driverStats.earnings_today', 15)
            ->where('driverStats.earnings_this_month', 35)
            ->where('driverStats.completed_rides_today', 1)
            ->where('driverStats.completed_rides_this_month', 2)
            ->where('driverStats.completed_rides', 2)
        );
    }

    /**
     * Pedido explícito del usuario: "mostrarle al conductor en la pantalla
     * principal el costo que el tiene por km y el costo base por carrera
     * que el tiene declarado" — de solo lectura acá, con un link en
     * Dashboard.vue al formulario para corregirlos.
     */
    public function test_driver_stats_include_their_declared_rates(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['rate_per_km' => 0.45, 'minimum_fare' => 2.5]);

        $response = $this->actingAs($driver)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('driverStats.rate_per_km', 0.45)
            ->where('driverStats.minimum_fare', 2.5)
        );
    }

    public function test_client_does_not_receive_invite_code_or_earnings_sparkline(): void
    {
        $client = User::factory()->create();
        Fleet::factory()->for($client, 'owner')->create();

        $response = $this->actingAs($client)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('inviteCode', null)
            ->where('earningsSparkline', null)
        );
    }

    /**
     * Pedido explícito del usuario: el botón "Activarme" de Inicio ofrece
     * conectar WhatsApp si todavía no tiene la ventana de 24h abierta.
     */
    public function test_driver_without_a_whatsapp_session_receives_null(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $response = $this->actingAs($driver)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page->where('whatsappSession', null));
    }

    public function test_driver_with_an_active_whatsapp_session_receives_its_status(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        WhatsAppSession::query()->create([
            'user_id' => $driver->id, 'opened_at' => now(), 'expires_at' => now()->addHours(20),
        ]);

        $response = $this->actingAs($driver)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page->where('whatsappSession.status', 'active'));
    }

    public function test_client_does_not_receive_a_whatsapp_session(): void
    {
        $client = User::factory()->create();
        Fleet::factory()->for($client, 'owner')->create();

        $response = $this->actingAs($client)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page->where('whatsappSession', null));
    }

    /**
     * Las carreras inmediatas bloquean correctamente Inicio. Las programadas
     * sí pueden convivir con la navegación y deben aparecer como próximas.
     */
    public function test_upcoming_trips_for_a_client_includes_pending_and_accepted_scheduled_trips(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = User::factory()->create(['name' => 'Conductor Pendiente']);
        DriverProfile::factory()->for($driver)->create();
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        RideRequest::factory()->for($fleet)->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'pending',
            'is_scheduled' => true,
            'scheduled_at' => now()->addHours(3),
            'current_offered_price' => 5.5,
        ]);

        $confirmedDriver = User::factory()->create(['name' => 'Conductor Confirmado']);
        $confirmedRequest = RideRequest::factory()->for($fleet)->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $confirmedDriver->id,
            'status' => 'accepted',
            'is_scheduled' => true,
            'scheduled_at' => now()->addHours(4),
        ]);
        Ride::factory()->for($confirmedRequest, 'rideRequest')->for($fleet)->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $confirmedDriver->id,
            'status' => 'scheduled',
            'price' => 12,
        ]);

        $response = $this->actingAs($client)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('upcomingTrips', fn ($trips) => collect($trips)->contains(fn ($trip) => $trip['status'] === 'pending' && $trip['counterpart_name'] === 'Conductor Pendiente' && (float) $trip['price'] === 5.5)
                && collect($trips)->contains(fn ($trip) => $trip['status'] === 'scheduled' && $trip['counterpart_name'] === 'Conductor Confirmado' && (float) $trip['price'] === 12.0)
            )
        );
    }

    public function test_upcoming_trips_for_a_driver_includes_an_incoming_pending_request(): void
    {
        $client = User::factory()->create(['name' => 'Cliente Pendiente']);
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        RideRequest::factory()->for($fleet)->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($driver)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('upcomingTrips', fn ($trips) => collect($trips)->contains(fn ($trip) => $trip['status'] === 'pending' && $trip['counterpart_name'] === 'Cliente Pendiente')
            )
        );
    }

    public function test_an_admin_does_not_see_any_home_section(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('driverStats', null)
            ->where('fleetDrivers', null)
            ->where('nearbyDrivers', null)
            ->where('upcomingTrips', null)
            ->where('inviteCode', null)
            ->where('earningsSparkline', null)
        );
    }

    public function test_a_non_admin_does_not_receive_admin_stats(): void
    {
        $client = User::factory()->create();
        Fleet::factory()->for($client, 'owner')->create();

        $response = $this->actingAs($client)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page->where('adminStats', null));
    }

    /**
     * Pedido explícito del usuario: "indicadores en el dashboard...
     * personas registradas, Pasajeros, conductores, cooperativas, esta
     * semana. este mes. hoy" — cada balde cuenta ALTAS nuevas desde ese
     * inicio de período (hoy 00:00, lunes de esta semana, día 1 de este
     * mes), no un total acumulado.
     */
    public function test_an_admin_receives_registration_counts_broken_down_by_period(): void
    {
        // Miércoles a mitad de mes: ni el inicio de semana ni el de mes caen
        // el mismo día que "hoy", así el test distingue los tres baldes.
        Carbon::setTestNow(Carbon::parse('2026-03-18 12:00:00'));

        $admin = User::factory()->create(['is_admin' => true]);

        $clientToday = User::factory()->create();
        $clientToday->forceFill(['created_at' => now()])->save();

        $driverThisWeekNotToday = User::factory()->create();
        DriverProfile::factory()->for($driverThisWeekNotToday)->create();
        $driverThisWeekNotToday->forceFill(['created_at' => now()->subDays(2)])->save();

        $clientThisMonthNotThisWeek = User::factory()->create();
        $clientThisMonthNotThisWeek->forceFill(['created_at' => now()->subDays(10)])->save();

        $driverLastMonth = User::factory()->create();
        DriverProfile::factory()->for($driverLastMonth)->create();
        $driverLastMonth->forceFill(['created_at' => now()->subMonths(2)])->save();

        $cooperativeOwner = User::factory()->create();
        $cooperative = Cooperative::query()->create(['user_id' => $cooperativeOwner->id, 'name' => 'Coop de prueba']);
        $cooperative->forceFill(['status' => 'approved'])->save();

        $response = $this->actingAs($admin)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('adminStats.clients.today', 1)
            ->where('adminStats.clients.week', 1)
            ->where('adminStats.clients.month', 2)
            ->where('adminStats.drivers.today', 0)
            ->where('adminStats.drivers.week', 1)
            ->where('adminStats.drivers.month', 1)
            ->where('adminStats.cooperatives.today', 1)
            ->where('adminStats.cooperatives.week', 1)
            ->where('adminStats.cooperatives.month', 1)
            // "Personas registradas" (people) suma TODOS los roles, incluido
            // el propio admin y el dueño de la cooperativa.
            ->where('adminStats.people.total', 6)
        );

        Carbon::setTestNow();
    }
}
