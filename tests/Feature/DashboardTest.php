<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetInvitation;
use App\Models\FleetMember;
use App\Models\Review;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\User;
use App\Models\WhatsAppSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $rideRequest = RideRequest::factory()->for($fleet)->create(['client_user_id' => $client->id, 'driver_user_id' => $busy->id]);
        Ride::factory()->for($rideRequest, 'rideRequest')->for($fleet)->create([
            'client_user_id' => $client->id,
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

        $response = $this->actingAs($client)->get(route('dashboard', ['lat' => -0.1807, 'lng' => -78.4678]));

        $response->assertInertia(fn ($page) => $page
            ->where('fleetDrivers.0.average_rating', fn ($rating) => (float) $rating === 5.0)
            ->where('fleetDrivers.0.review_count', 1)
            ->where('fleetDrivers.0.distance_km', fn ($distance) => $distance < 0.1)
        );
    }

    public function test_nearby_drivers_excludes_the_viewer_and_their_own_fleet_members(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();

        $fleetMember = User::factory()->create();
        DriverProfile::factory()->for($fleetMember)->create(['is_public' => true, 'is_available' => true]);
        FleetMember::factory()->for($fleet)->for($fleetMember, 'driver')->create(['added_by' => $client->id]);

        $publicDriver = User::factory()->create();
        DriverProfile::factory()->for($publicDriver)->create(['is_public' => true, 'is_available' => true]);

        $response = $this->actingAs($client)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('nearbyDrivers', fn ($drivers) => collect($drivers)->contains('user_id', $publicDriver->id)
                && ! collect($drivers)->contains('user_id', $fleetMember->id)
                && ! collect($drivers)->contains('user_id', $client->id)
            )
        );
    }

    /**
     * Pedido explícito del usuario: "conductores que quizás conozcas...
     * cerca de donde viven" — si el navegador todavía no compartió
     * ubicación en vivo esta vez (recién entrando, antes del reload de
     * onMounted), se usa la que dio al registrarse.
     */
    public function test_nearby_drivers_falls_back_to_the_clients_registration_location(): void
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

        // Sin lat/lng por query string, como si el navegador todavía no
        // hubiera compartido ubicación en vivo esta vez.
        $response = $this->actingAs($client)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('nearbyDrivers.0.user_id', $publicDriver->id)
            ->where('nearbyDrivers.0.distance_km', fn ($km) => $km !== null)
        );
    }

    /**
     * Pedido explícito del usuario: "buscar otra datos [que coincidan]" —
     * sin ninguna coordenada de ningún lado, la misma ciudad declarada
     * también cuenta como una coincidencia real.
     */
    public function test_nearby_drivers_falls_back_to_the_same_city_when_no_coordinates_are_available(): void
    {
        $quito = City::query()->create(['name' => 'Quito', 'is_active' => true]);
        $guayaquil = City::query()->create(['name' => 'Guayaquil', 'is_active' => true]);

        $client = User::factory()->create(['city_id' => $quito->id]);

        $sameCityDriver = User::factory()->create(['city_id' => $quito->id]);
        DriverProfile::factory()->for($sameCityDriver)->create(['is_public' => true, 'is_available' => true]);

        $otherCityDriver = User::factory()->create(['city_id' => $guayaquil->id]);
        DriverProfile::factory()->for($otherCityDriver)->create(['is_public' => true, 'is_available' => true]);

        $response = $this->actingAs($client)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('nearbyDrivers.0.user_id', $sameCityDriver->id)
            ->where('nearbyDrivers.0.same_city', true)
            ->where('nearbyDrivers.1.same_city', false)
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
     * "Próximos viajes" del cliente (mockup): no hay agendar a futuro, así que
     * son los viajes reales — una solicitud todavía pendiente y una carrera
     * ya confirmada (en curso).
     */
    public function test_upcoming_trips_for_a_client_includes_pending_and_confirmed_trips(): void
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
            'current_offered_price' => 5.5,
        ]);

        $confirmedDriver = User::factory()->create(['name' => 'Conductor Confirmado']);
        $confirmedRequest = RideRequest::factory()->for($fleet)->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $confirmedDriver->id,
            'status' => 'accepted',
        ]);
        Ride::factory()->for($confirmedRequest, 'rideRequest')->for($fleet)->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $confirmedDriver->id,
            'status' => 'in_progress',
            'price' => 12,
        ]);

        $response = $this->actingAs($client)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('upcomingTrips', fn ($trips) => collect($trips)->contains(fn ($trip) => $trip['status'] === 'pending' && $trip['counterpart_name'] === 'Conductor Pendiente' && (float) $trip['price'] === 5.5)
                && collect($trips)->contains(fn ($trip) => $trip['status'] === 'confirmed' && $trip['counterpart_name'] === 'Conductor Confirmado' && (float) $trip['price'] === 12.0)
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
}
