<?php

namespace Tests\Feature\Api\V1;

use App\Models\ExpressRoute;
use App\Models\ExpressRouteCompanion;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Compartir un Expreso con acompañantes, y reportar incumplimientos, desde
 * la app móvil (roadmap Hito 5, pasada "full backend" continuada). Reusa
 * exactamente los mismos servicios que la web
 * (tests\Feature\Express\ExpressRouteSharingTest), así que estos casos se
 * enfocan en el contrato JSON y la autorización del canal móvil.
 *
 * Nota (ver feedback_sanctum_guard_test_caching en memoria): cada método de
 * test usa un solo Bearer token — dos peticiones con tokens de usuarios
 * distintos en el mismo método autentican mal la segunda.
 */
class MobileExpressSharingTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function clientWithFleetDriver(): array
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();

        $fleet = Fleet::factory()->for($client, 'owner')->create();
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        return [$client, $driver, $fleet];
    }

    private function shareableRoute(User $client, array $overrides = []): ExpressRoute
    {
        return ExpressRoute::query()->create(array_merge([
            'client_user_id' => $client->id,
            'name' => 'Turno mañana',
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
            'days_of_week' => [1, 2, 3, 4, 5],
            'departure_time' => '07:30',
            'offered_price' => 10,
            'status' => 'open',
            'share_enabled' => true,
            'max_companions' => 2,
        ], $overrides));
    }

    public function test_discover_finds_a_nearby_shareable_route(): void
    {
        [$owner] = $this->clientWithFleetDriver();
        $route = $this->shareableRoute($owner);
        $seeker = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($seeker))
            ->getJson('/api/v1/express-routes/discover?'.http_build_query([
                'origin_lat' => -0.181, 'origin_lng' => -78.468,
                'destination_lat' => -0.201, 'destination_lng' => -78.501,
            ]));

        $response->assertOk()->assertJsonCount(1, 'routes')->assertJsonPath('routes.0.id', $route->id);
    }

    public function test_discover_excludes_a_route_that_is_far_away(): void
    {
        [$owner] = $this->clientWithFleetDriver();
        $this->shareableRoute($owner, ['origin_lat' => -2.20, 'origin_lng' => -79.90, 'destination_lat' => -2.15, 'destination_lng' => -79.88]);
        $seeker = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($seeker))
            ->getJson('/api/v1/express-routes/discover?'.http_build_query([
                'origin_lat' => -0.181, 'origin_lng' => -78.468,
                'destination_lat' => -0.201, 'destination_lng' => -78.501,
            ]));

        $response->assertOk()->assertJsonCount(0, 'routes');
    }

    public function test_discover_excludes_routes_not_open_to_sharing(): void
    {
        [$owner] = $this->clientWithFleetDriver();
        $this->shareableRoute($owner, ['share_enabled' => false]);
        $seeker = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($seeker))
            ->getJson('/api/v1/express-routes/discover?'.http_build_query([
                'origin_lat' => -0.181, 'origin_lng' => -78.468,
                'destination_lat' => -0.201, 'destination_lng' => -78.501,
            ]));

        $response->assertOk()->assertJsonCount(0, 'routes');
    }

    public function test_a_client_can_request_to_join_a_shareable_route(): void
    {
        [$owner] = $this->clientWithFleetDriver();
        $route = $this->shareableRoute($owner);
        $seeker = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($seeker))
            ->postJson("/api/v1/express-routes/{$route->id}/companions");

        $response->assertCreated()->assertJsonPath('companion.status', 'pending');
        $this->assertDatabaseHas('express_route_companions', [
            'express_route_id' => $route->id,
            'passenger_user_id' => $seeker->id,
            'status' => 'pending',
        ]);
    }

    public function test_the_owner_cannot_request_to_join_their_own_route(): void
    {
        [$owner] = $this->clientWithFleetDriver();
        $route = $this->shareableRoute($owner);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/express-routes/{$route->id}/companions")
            ->assertForbidden();
    }

    public function test_cannot_request_to_join_a_full_route(): void
    {
        [$owner] = $this->clientWithFleetDriver();
        $route = $this->shareableRoute($owner, ['max_companions' => 1]);

        $alreadyAccepted = User::factory()->create();
        ExpressRouteCompanion::query()->create([
            'express_route_id' => $route->id,
            'passenger_user_id' => $alreadyAccepted->id,
            'status' => 'accepted',
            'requested_at' => now(),
            'responded_at' => now(),
        ]);

        $seeker = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($seeker))
            ->postJson("/api/v1/express-routes/{$route->id}/companions")
            ->assertUnprocessable();
    }

    public function test_the_owner_can_accept_a_pending_companion_request(): void
    {
        [$owner] = $this->clientWithFleetDriver();
        $route = $this->shareableRoute($owner);
        $seeker = User::factory()->create();

        $companion = ExpressRouteCompanion::query()->create([
            'express_route_id' => $route->id,
            'passenger_user_id' => $seeker->id,
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/express-companions/{$companion->id}/accept")
            ->assertOk()
            ->assertJsonPath('companion.status', 'accepted');
    }

    public function test_a_stranger_cannot_accept_a_companion_request_on_someone_elses_route(): void
    {
        [$owner] = $this->clientWithFleetDriver();
        $route = $this->shareableRoute($owner);
        $seeker = User::factory()->create();
        $stranger = User::factory()->create();

        $companion = ExpressRouteCompanion::query()->create([
            'express_route_id' => $route->id,
            'passenger_user_id' => $seeker->id,
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->postJson("/api/v1/express-companions/{$companion->id}/accept")
            ->assertForbidden();
    }

    public function test_the_assigned_driver_can_confirm_an_accepted_companion(): void
    {
        [$owner, $driver] = $this->clientWithFleetDriver();
        $route = $this->shareableRoute($owner, [
            'status' => 'active',
            'assigned_driver_user_id' => $driver->id,
            'assigned_at' => now(),
        ]);
        $companion = ExpressRouteCompanion::query()->create([
            'express_route_id' => $route->id,
            'passenger_user_id' => User::factory()->create()->id,
            'status' => 'accepted',
            'driver_approval_status' => 'pending',
            'requested_at' => now(),
            'responded_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->postJson("/api/v1/express-companions/{$companion->id}/driver-accept")
            ->assertOk()
            ->assertJsonPath('companion.driver_approval_status', 'accepted');
    }

    public function test_a_different_driver_cannot_decide_about_a_companion(): void
    {
        [$owner, $driver] = $this->clientWithFleetDriver();
        $route = $this->shareableRoute($owner, [
            'status' => 'active',
            'assigned_driver_user_id' => $driver->id,
            'assigned_at' => now(),
        ]);
        $companion = ExpressRouteCompanion::query()->create([
            'express_route_id' => $route->id,
            'passenger_user_id' => User::factory()->create()->id,
            'status' => 'accepted',
            'driver_approval_status' => 'pending',
            'requested_at' => now(),
        ]);

        $otherDriver = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($otherDriver))
            ->postJson("/api/v1/express-companions/{$companion->id}/driver-accept")
            ->assertForbidden();
    }

    public function test_a_passenger_can_leave_a_shared_route(): void
    {
        [$owner] = $this->clientWithFleetDriver();
        $route = $this->shareableRoute($owner);
        $seeker = User::factory()->create();

        $companion = ExpressRouteCompanion::query()->create([
            'express_route_id' => $route->id,
            'passenger_user_id' => $seeker->id,
            'status' => 'accepted',
            'requested_at' => now(),
            'responded_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($seeker))
            ->postJson("/api/v1/express-companions/{$companion->id}/leave")
            ->assertOk()
            ->assertJsonPath('companion.status', 'left');
    }

    public function test_a_passenger_cannot_make_someone_elses_companion_leave(): void
    {
        [$owner] = $this->clientWithFleetDriver();
        $route = $this->shareableRoute($owner);
        $seeker = User::factory()->create();
        $stranger = User::factory()->create();

        $companion = ExpressRouteCompanion::query()->create([
            'express_route_id' => $route->id,
            'passenger_user_id' => $seeker->id,
            'status' => 'accepted',
            'requested_at' => now(),
            'responded_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->postJson("/api/v1/express-companions/{$companion->id}/leave")
            ->assertForbidden();
    }

    // --- Incidentes ---

    public function test_a_client_can_report_an_incident_tied_to_the_routes_ride(): void
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

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->postJson("/api/v1/express-routes/{$route->id}/incidents", [
                'ride_id' => $ride->id,
                'express_condition_id' => $condition->id,
                'description' => 'No tenía aire acondicionado prendido.',
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('express_incidents', [
            'express_route_id' => $route->id,
            'ride_id' => $ride->id,
            'reported_by' => $client->id,
        ]);
    }

    public function test_cannot_report_an_incident_for_an_unrelated_ride(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $route = ExpressRoute::query()->create([
            'client_user_id' => $client->id, 'name' => 'Turno', 'origin_lat' => -0.18, 'origin_lng' => -78.46,
            'destination_lat' => -0.2, 'destination_lng' => -78.5, 'days_of_week' => [1], 'departure_time' => '07:00',
            'offered_price' => 5, 'status' => 'active', 'assigned_driver_user_id' => $driver->id, 'assigned_at' => now(),
        ]);

        $unrelatedRide = Ride::factory()->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'completed',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->postJson("/api/v1/express-routes/{$route->id}/incidents", [
                'ride_id' => $unrelatedRide->id,
                'description' => 'Queja inválida.',
            ])
            ->assertUnprocessable();
    }

    public function test_a_stranger_cannot_report_an_incident(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();
        $route = ExpressRoute::query()->create([
            'client_user_id' => $client->id, 'name' => 'Turno', 'origin_lat' => -0.18, 'origin_lng' => -78.46,
            'destination_lat' => -0.2, 'destination_lng' => -78.5, 'days_of_week' => [1], 'departure_time' => '07:00',
            'offered_price' => 5, 'status' => 'active', 'assigned_driver_user_id' => $driver->id, 'assigned_at' => now(),
        ]);
        $stranger = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->postJson("/api/v1/express-routes/{$route->id}/incidents", [
                'ride_id' => 1,
                'description' => 'x',
            ])
            ->assertForbidden();
    }
}
