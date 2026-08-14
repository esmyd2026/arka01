<?php

namespace Tests\Feature\Express;

use App\Console\Commands\GenerateExpressRides;
use App\Models\City;
use App\Models\DriverProfile;
use App\Models\ExpressApplication;
use App\Models\ExpressRoute;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Notifications\ExpressApplicationResultPushNotification;
use App\Notifications\RideRequestedPushNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Fase 6 (sección 4 del alcance): publicar un Expreso, que un conductor de la
 * flota se postule, que el cliente acepte una postulación (el Expreso queda
 * activo con ese conductor) y que el motor de recurrencia genere la carrera
 * del día automáticamente.
 */
class ExpressRouteFlowTest extends TestCase
{
    use RefreshDatabase;

    private function clientWithFleetDriver(): array
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();

        $fleet = Fleet::factory()->for($client, 'owner')->create();
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        return [$client, $driver, $fleet];
    }

    public function test_client_can_publish_an_express_route_with_conditions(): void
    {
        $client = User::factory()->create();

        $response = $this->actingAs($client)->post(route('express-routes.store'), [
            'name' => 'Turno mañana',
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
            'days_of_week' => [1, 2, 3, 4, 5],
            'departure_time' => '07:30',
            'offered_price' => 5,
            'conditions' => ['Aire acondicionado', 'Puntualidad'],
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('express_routes', [
            'client_user_id' => $client->id,
            'name' => 'Turno mañana',
            'status' => 'open',
        ]);

        $route = ExpressRoute::firstOrFail();
        $this->assertSame(2, $route->conditions()->count());
    }

    // Costo estimado y piso de precio (pedido explícito del usuario: "el
    // precio que coloque que no sea menor al estimado").

    public function test_the_index_screen_exposes_the_reference_rate_and_minimum_fare(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();
        DriverProfile::factory()->for($driver)->create(['rate_per_km' => 0.5]);

        $response = $this->actingAs($client)->get(route('express-routes.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('referenceRatePerKm', 0.5)
            ->has('minimumFare')
        );
    }

    /**
     * Bug reportado por el usuario ("el mapa al inicio no se posiciona donde
     * está el cliente... por lo menos en la ciudad"): respaldo si el
     * navegador no da geolocalización a tiempo.
     */
    public function test_the_index_screen_exposes_the_clients_city_for_the_map(): void
    {
        $quito = City::query()->where('name', 'Quito')->firstOrFail();
        $client = User::factory()->create(['city_id' => $quito->id]);

        $response = $this->actingAs($client)->get(route('express-routes.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('clientCity.lat', (float) $quito->lat)
            ->where('clientCity.lng', (float) $quito->lng)
        );
    }

    public function test_the_index_screen_has_no_client_city_without_one_set(): void
    {
        $client = User::factory()->create(['city_id' => null]);

        $response = $this->actingAs($client)->get(route('express-routes.index'));

        $response->assertInertia(fn ($page) => $page->where('clientCity', null));
    }

    /**
     * Bug reportado por el usuario ("sale $0, deberías sacar un estimado"):
     * un cliente recién empezando, sin ningún conductor todavía en su
     * flota, no puede quedarse sin estimado — se usa el promedio de tarifa
     * de TODA la plataforma en ese caso.
     */
    public function test_the_reference_rate_falls_back_to_the_platform_average_without_fleet_drivers(): void
    {
        $client = User::factory()->create(); // sin flota ni conductores todavía

        $someoneElsesDriver = User::factory()->create();
        DriverProfile::factory()->for($someoneElsesDriver)->create(['rate_per_km' => 0.8]);

        $response = $this->actingAs($client)->get(route('express-routes.index'));

        $response->assertInertia(fn ($page) => $page->where('referenceRatePerKm', 0.8));
    }

    public function test_publishing_below_half_the_estimated_price_fails_validation(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();
        DriverProfile::factory()->for($driver)->create(['rate_per_km' => 1]);

        // origen/destino separados ~2.4km — a $1/km, el estimado ronda los $2.40,
        // el piso real (50%) ronda el $1.20 — bien por debajo de los dos.
        $response = $this->actingAs($client)->post(route('express-routes.store'), [
            'name' => 'Turno mañana',
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.4800,
            'days_of_week' => [1],
            'departure_time' => '07:30',
            'offered_price' => 0.5,
        ]);

        $response->assertSessionHasErrors('offered_price');
        $this->assertDatabaseCount('express_routes', 0);
    }

    /**
     * Pedido explícito del usuario: no tiene que calzar con el estimado —
     * alcanza con no irse por debajo de la mitad.
     */
    public function test_publishing_between_half_and_the_full_estimated_price_succeeds(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();
        DriverProfile::factory()->for($driver)->create(['rate_per_km' => 1]);

        // origen/destino separados ~2.4km — a $1/km, el estimado ronda los $2.40,
        // el piso real (50%) ronda el $1.20 — $1.50 cae en el medio.
        $response = $this->actingAs($client)->post(route('express-routes.store'), [
            'name' => 'Turno mañana',
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.4800,
            'days_of_week' => [1],
            'departure_time' => '07:30',
            'offered_price' => 1.5,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('express_routes', ['client_user_id' => $client->id, 'offered_price' => 1.5]);
    }

    // Ida y vuelta (pedido explícito del usuario): una segunda hora, de vuelta.

    public function test_publishing_a_round_trip_requires_a_return_time(): void
    {
        $client = User::factory()->create();

        $response = $this->actingAs($client)->post(route('express-routes.store'), [
            'name' => 'Turno mañana',
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
            'days_of_week' => [1],
            'departure_time' => '07:30',
            'is_round_trip' => true,
            // Sin return_time a propósito.
            'offered_price' => 5,
        ]);

        $response->assertSessionHasErrors('return_time');
        $this->assertDatabaseCount('express_routes', 0);
    }

    public function test_publishing_a_round_trip_stores_both_times(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client)->post(route('express-routes.store'), [
            'name' => 'Turno mañana',
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
            'days_of_week' => [1],
            'departure_time' => '07:30',
            'is_round_trip' => true,
            'return_time' => '17:00',
            'offered_price' => 5,
        ])->assertRedirect();

        $route = ExpressRoute::query()->where('client_user_id', $client->id)->firstOrFail();
        $this->assertTrue($route->is_round_trip);
        $this->assertSame('17:00', substr($route->return_time, 0, 5));
    }

    public function test_generate_express_rides_creates_both_legs_for_a_round_trip_route(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 00:00:00')); // lunes

        [$client, $driver] = $this->clientWithFleetDriver();

        $route = ExpressRoute::query()->create([
            'client_user_id' => $client->id,
            'name' => 'Turno ida y vuelta',
            'origin_lat' => -0.1807, 'origin_lng' => -78.4678,
            'destination_lat' => -0.2000, 'destination_lng' => -78.5000,
            'days_of_week' => [1],
            'departure_time' => '07:30',
            'is_round_trip' => true,
            'return_time' => '17:00',
            'offered_price' => 5,
            'status' => 'active',
            'assigned_driver_user_id' => $driver->id,
            'assigned_at' => now(),
        ]);

        $this->artisan(GenerateExpressRides::class)->assertSuccessful();

        $this->assertSame(2, RideRequest::where('express_route_id', $route->id)->count());

        $outbound = RideRequest::where('express_route_id', $route->id)->whereTime('requested_at', '07:30:00')->firstOrFail();
        $this->assertEquals(-0.1807, (float) $outbound->origin_lat);
        $this->assertEquals(-0.2000, (float) $outbound->destination_lat);

        $return = RideRequest::where('express_route_id', $route->id)->whereTime('requested_at', '17:00:00')->firstOrFail();
        $this->assertEquals(-0.2000, (float) $return->origin_lat);
        $this->assertEquals(-0.1807, (float) $return->destination_lat);

        // Correrlo de nuevo el mismo día no debería duplicar ninguna de las dos.
        $this->artisan(GenerateExpressRides::class)->assertSuccessful();
        $this->assertSame(2, RideRequest::where('express_route_id', $route->id)->count());
    }

    /**
     * Bug real reportado por el usuario ("el conductor no solicita
     * servicios, recordá eso" — vio un link "Publicar el mío" en su propia
     * pantalla de Expresos disponibles, ya sacado): publicar un Expreso es
     * una acción de cliente, un conductor no puede hacerlo ni por URL directa.
     */
    public function test_a_driver_cannot_publish_an_express_route(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $response = $this->actingAs($driver)->post(route('express-routes.store'), [
            'name' => 'Turno mañana',
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
            'days_of_week' => [1, 2, 3, 4, 5],
            'departure_time' => '07:30',
            'offered_price' => 5,
        ]);

        $response->assertSessionHasErrors('name');
        $this->assertDatabaseMissing('express_routes', ['client_user_id' => $driver->id]);
    }

    public function test_a_driver_is_redirected_away_from_their_own_express_routes_screen(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $response = $this->actingAs($driver)->get(route('express-routes.index'));

        $response->assertRedirect(route('dashboard'));
    }

    public function test_a_fleet_driver_sees_the_open_route_and_can_apply(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $route = ExpressRoute::query()->create([
            'client_user_id' => $client->id,
            'name' => 'Turno mañana',
            'origin_lat' => -0.1807, 'origin_lng' => -78.4678,
            'destination_lat' => -0.2000, 'destination_lng' => -78.5000,
            'days_of_week' => [1, 2, 3, 4, 5],
            'departure_time' => '07:30',
            'offered_price' => 5,
            'status' => 'open',
        ]);

        $response = $this->actingAs($driver)->get(route('express-routes.available'));
        $response->assertInertia(fn ($page) => $page
            ->component('Express/Available')
            ->has('routes', 1)
            ->where('routes.0.id', $route->id)
        );

        $this->actingAs($driver)
            ->post(route('express-applications.store', $route), ['proposed_price' => 6])
            ->assertRedirect();

        $this->assertDatabaseHas('express_applications', [
            'express_route_id' => $route->id,
            'driver_user_id' => $driver->id,
            'proposed_price' => 6,
            'status' => 'pending',
        ]);
    }

    /**
     * Pedido explícito del usuario ("no sé a quién le aparece un Expreso"):
     * la pantalla necesita poder distinguir "no pertenezco a ninguna flota"
     * de "pertenezco, pero no hay nada abierto ahora" — mismo dato que usa
     * Express/Available.vue para el mensaje de la lista vacía.
     */
    public function test_available_reports_how_many_fleets_the_driver_belongs_to(): void
    {
        $driverWithNoFleet = User::factory()->create();
        DriverProfile::factory()->for($driverWithNoFleet)->create();

        $this->actingAs($driverWithNoFleet)->get(route('express-routes.available'))
            ->assertInertia(fn ($page) => $page->where('myFleetCount', 0));

        [, $driverInFleet] = $this->clientWithFleetDriver();

        $this->actingAs($driverInFleet)->get(route('express-routes.available'))
            ->assertInertia(fn ($page) => $page->where('myFleetCount', 1));
    }

    /**
     * Pedido explícito del usuario: módulo de Expresos habilitable por plan
     * de conductor — a diferencia de VAN (una función nueva), acá el default
     * es "habilitado" salvo que un admin lo apague puntualmente.
     */
    public function test_a_driver_without_the_plan_feature_sees_the_list_but_cannot_apply(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $plan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'basico')->firstOrFail();
        $plan->update(['express_enabled' => false]);
        Subscription::factory()->for($driver)->create(['subscription_plan_id' => $plan->id, 'status' => 'active']);

        $route = ExpressRoute::query()->create([
            'client_user_id' => $client->id,
            'name' => 'Turno mañana',
            'origin_lat' => -0.1807, 'origin_lng' => -78.4678,
            'destination_lat' => -0.2000, 'destination_lng' => -78.5000,
            'days_of_week' => [1, 2, 3, 4, 5],
            'departure_time' => '07:30',
            'offered_price' => 5,
            'status' => 'open',
        ]);

        $this->actingAs($driver)->get(route('express-routes.available'))
            ->assertInertia(fn ($page) => $page->has('routes', 1)->where('canApply', false));

        $this->actingAs($driver)
            ->post(route('express-applications.store', $route), ['proposed_price' => 6])
            ->assertSessionHasErrors('route');

        $this->assertDatabaseCount('express_applications', 0);
    }

    public function test_a_driver_outside_the_fleet_does_not_see_the_route(): void
    {
        [$client] = $this->clientWithFleetDriver();
        $outsider = User::factory()->create();

        ExpressRoute::query()->create([
            'client_user_id' => $client->id,
            'name' => 'Turno mañana',
            'origin_lat' => -0.1807, 'origin_lng' => -78.4678,
            'destination_lat' => -0.2000, 'destination_lng' => -78.5000,
            'days_of_week' => [1, 2, 3, 4, 5],
            'departure_time' => '07:30',
            'offered_price' => 5,
            'status' => 'open',
        ]);

        $response = $this->actingAs($outsider)->get(route('express-routes.available'));
        $response->assertInertia(fn ($page) => $page->has('routes', 0));
    }

    public function test_client_accepting_an_application_activates_the_route_and_rejects_the_rest(): void
    {
        Notification::fake();
        [$client, $driverA, $fleet] = $this->clientWithFleetDriver();
        $driverB = User::factory()->create();
        FleetMember::factory()->for($fleet)->for($driverB, 'driver')->create(['added_by' => $client->id]);

        $route = ExpressRoute::query()->create([
            'client_user_id' => $client->id,
            'name' => 'Turno mañana',
            'origin_lat' => -0.1807, 'origin_lng' => -78.4678,
            'destination_lat' => -0.2000, 'destination_lng' => -78.5000,
            'days_of_week' => [1, 2, 3, 4, 5],
            'departure_time' => '07:30',
            'offered_price' => 5,
            'status' => 'open',
        ]);

        $applicationA = ExpressApplication::query()->create([
            'express_route_id' => $route->id, 'driver_user_id' => $driverA->id, 'status' => 'pending', 'applied_at' => now(),
        ]);
        $applicationB = ExpressApplication::query()->create([
            'express_route_id' => $route->id, 'driver_user_id' => $driverB->id, 'status' => 'pending', 'applied_at' => now(),
        ]);

        $this->actingAs($client)
            ->post(route('express-applications.accept', $applicationA))
            ->assertRedirect();

        $route->refresh();
        $this->assertSame('active', $route->status);
        $this->assertSame($driverA->id, $route->assigned_driver_user_id);
        $this->assertSame('accepted', $applicationA->fresh()->status);
        $this->assertSame('rejected', $applicationB->fresh()->status);
        Notification::assertSentTo($driverA, ExpressApplicationResultPushNotification::class);
        Notification::assertSentTo($driverB, ExpressApplicationResultPushNotification::class);
    }

    public function test_an_assigned_driver_has_a_persistent_express_panel(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();
        $route = ExpressRoute::query()->create([
            'client_user_id' => $client->id,
            'assigned_driver_user_id' => $driver->id,
            'name' => 'Trabajo diario',
            'origin_lat' => -0.18, 'origin_lng' => -78.46,
            'destination_lat' => -0.2, 'destination_lng' => -78.5,
            'days_of_week' => [1, 2, 3, 4, 5],
            'departure_time' => '07:00',
            'offered_price' => 5,
            'status' => 'active',
            'assigned_at' => now(),
        ]);

        $this->actingAs($driver)->get(route('express-routes.available'))
            ->assertInertia(fn ($page) => $page
                ->has('assignedRoutes', 1)
                ->where('assignedRoutes.0.id', $route->id)
                ->where('assignedRoutes.0.client.id', $client->id)
                ->whereNot('assignedRoutes.0.next_run_at', null));
    }

    public function test_generated_express_request_is_scheduled_and_notifies_the_driver(): void
    {
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-08-03 05:00:00')); // lunes
        [$client, $driver] = $this->clientWithFleetDriver();
        $route = ExpressRoute::query()->create([
            'client_user_id' => $client->id,
            'assigned_driver_user_id' => $driver->id,
            'name' => 'Trabajo diario',
            'origin_lat' => -0.18, 'origin_lng' => -78.46,
            'destination_lat' => -0.2, 'destination_lng' => -78.5,
            'days_of_week' => [1],
            'departure_time' => '07:00',
            'offered_price' => 5,
            'status' => 'active',
            'assigned_at' => now(),
        ]);

        $this->artisan(GenerateExpressRides::class)->assertSuccessful();

        $request = RideRequest::where('express_route_id', $route->id)->firstOrFail();
        $this->assertTrue($request->is_scheduled);
        $this->assertSame('2026-08-03 07:00:00', $request->scheduled_at->format('Y-m-d H:i:s'));
        Notification::assertSentTo($driver, RideRequestedPushNotification::class);
    }

    public function test_a_driver_can_withdraw_their_own_pending_application(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $route = ExpressRoute::query()->create([
            'client_user_id' => $client->id, 'name' => 'Turno', 'origin_lat' => -0.18, 'origin_lng' => -78.46,
            'destination_lat' => -0.2, 'destination_lng' => -78.5, 'days_of_week' => [1], 'departure_time' => '07:00',
            'offered_price' => 5, 'status' => 'open',
        ]);

        $application = ExpressApplication::query()->create([
            'express_route_id' => $route->id, 'driver_user_id' => $driver->id, 'status' => 'pending', 'applied_at' => now(),
        ]);

        $this->actingAs($driver)
            ->post(route('express-applications.withdraw', $application))
            ->assertRedirect();

        $this->assertSame('withdrawn', $application->fresh()->status);
    }

    public function test_only_the_owner_can_update_or_cancel_their_express_route(): void
    {
        [$client] = $this->clientWithFleetDriver();
        $stranger = User::factory()->create();

        $route = ExpressRoute::query()->create([
            'client_user_id' => $client->id, 'name' => 'Turno', 'origin_lat' => -0.18, 'origin_lng' => -78.46,
            'destination_lat' => -0.2, 'destination_lng' => -78.5, 'days_of_week' => [1], 'departure_time' => '07:00',
            'offered_price' => 5, 'status' => 'open',
        ]);

        $this->actingAs($stranger)->post(route('express-routes.cancel', $route))->assertForbidden();
    }

    public function test_generate_express_rides_creates_a_ride_request_for_an_active_route_on_a_matching_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 00:00:00')); // lunes

        [$client, $driver, $fleet] = $this->clientWithFleetDriver();

        $route = ExpressRoute::query()->create([
            'client_user_id' => $client->id,
            'name' => 'Turno mañana',
            'origin_lat' => -0.1807, 'origin_lng' => -78.4678,
            'destination_lat' => -0.2000, 'destination_lng' => -78.5000,
            'days_of_week' => [1], // lunes
            'departure_time' => '07:30',
            'offered_price' => 5,
            'status' => 'active',
            'assigned_driver_user_id' => $driver->id,
            'assigned_at' => now(),
        ]);

        $this->artisan(GenerateExpressRides::class)->assertSuccessful();

        $this->assertDatabaseHas('ride_requests', [
            'express_route_id' => $route->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'current_offered_price' => 5,
            'status' => 'pending',
        ]);

        // Correrlo de nuevo el mismo día no debería duplicar la solicitud.
        $this->artisan(GenerateExpressRides::class)->assertSuccessful();
        $this->assertSame(1, RideRequest::where('express_route_id', $route->id)->count());
    }

    public function test_generate_express_rides_skips_routes_not_scheduled_for_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 00:00:00')); // lunes

        [$client, $driver] = $this->clientWithFleetDriver();

        ExpressRoute::query()->create([
            'client_user_id' => $client->id,
            'name' => 'Turno fin de semana',
            'origin_lat' => -0.1807, 'origin_lng' => -78.4678,
            'destination_lat' => -0.2000, 'destination_lng' => -78.5000,
            'days_of_week' => [6], // sábado, hoy es lunes
            'departure_time' => '07:30',
            'offered_price' => 5,
            'status' => 'active',
            'assigned_driver_user_id' => $driver->id,
            'assigned_at' => now(),
        ]);

        $this->artisan(GenerateExpressRides::class)->assertSuccessful();

        $this->assertDatabaseCount('ride_requests', 0);
    }

    public function test_client_can_report_an_incident_tied_to_a_completed_ride_of_the_route(): void
    {
        [$client, $driver, $fleet] = $this->clientWithFleetDriver();

        $route = ExpressRoute::query()->create([
            'client_user_id' => $client->id, 'name' => 'Turno', 'origin_lat' => -0.18, 'origin_lng' => -78.46,
            'destination_lat' => -0.2, 'destination_lng' => -78.5, 'days_of_week' => [1], 'departure_time' => '07:00',
            'offered_price' => 5, 'status' => 'active', 'assigned_driver_user_id' => $driver->id, 'assigned_at' => now(),
        ]);

        $condition = $route->conditions()->create(['description' => 'Aire acondicionado']);

        $rideRequest = RideRequest::factory()->create([
            'fleet_id' => $fleet->id,
            'express_route_id' => $route->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'accepted',
        ]);

        $ride = Ride::factory()->create([
            'ride_request_id' => $rideRequest->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'completed',
        ]);

        $this->actingAs($client)->post(route('express-incidents.store', $route), [
            'ride_id' => $ride->id,
            'express_condition_id' => $condition->id,
            'description' => 'No tenía aire acondicionado prendido.',
        ])->assertRedirect();

        $this->assertDatabaseHas('express_incidents', [
            'express_route_id' => $route->id,
            'ride_id' => $ride->id,
            'express_condition_id' => $condition->id,
            'reported_by' => $client->id,
        ]);
    }

    public function test_cannot_report_an_incident_for_a_ride_that_does_not_belong_to_the_route(): void
    {
        [$client, $driver, $fleet] = $this->clientWithFleetDriver();

        $route = ExpressRoute::query()->create([
            'client_user_id' => $client->id, 'name' => 'Turno', 'origin_lat' => -0.18, 'origin_lng' => -78.46,
            'destination_lat' => -0.2, 'destination_lng' => -78.5, 'days_of_week' => [1], 'departure_time' => '07:00',
            'offered_price' => 5, 'status' => 'active', 'assigned_driver_user_id' => $driver->id, 'assigned_at' => now(),
        ]);

        // Carrera normal, no ligada a ningún Expreso.
        $unrelatedRide = Ride::factory()->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'completed',
        ]);

        $this->actingAs($client)->post(route('express-incidents.store', $route), [
            'ride_id' => $unrelatedRide->id,
            'description' => 'Queja inválida.',
        ])->assertSessionHasErrors('ride_id');
    }
}
