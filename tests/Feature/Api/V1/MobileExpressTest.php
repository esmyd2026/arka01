<?php

namespace Tests\Feature\Api\V1;

use App\Models\DriverProfile;
use App\Models\ExpressApplication;
use App\Models\ExpressRoute;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Expresos" desde la app móvil (roadmap Hito 5, pasada "full backend").
 * Reusa exactamente los mismos servicios que la web
 * (tests\Feature\Express\ExpressRouteFlowTest), así que estos casos se
 * enfocan en el contrato JSON y la autorización del canal móvil.
 */
class MobileExpressTest extends TestCase
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

    private function openRoute(int $clientUserId, array $overrides = []): ExpressRoute
    {
        return ExpressRoute::query()->create(array_merge([
            'client_user_id' => $clientUserId,
            'name' => 'Turno mañana',
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
            'days_of_week' => [1, 2, 3, 4, 5],
            'departure_time' => '07:30',
            'offered_price' => 5,
            'status' => 'open',
        ], $overrides));
    }

    public function test_a_client_can_publish_an_express_route(): void
    {
        $client = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->postJson('/api/v1/express-routes', [
                'name' => 'Turno mañana',
                'origin_lat' => -0.1807,
                'origin_lng' => -78.4678,
                'destination_lat' => -0.2000,
                'destination_lng' => -78.5000,
                'days_of_week' => [1, 2, 3, 4, 5],
                'departure_time' => '07:30',
                'offered_price' => 5,
                'conditions' => ['Aire acondicionado'],
            ]);

        $response->assertCreated()->assertJsonPath('route.status', 'open');
        $this->assertDatabaseHas('express_routes', ['client_user_id' => $client->id, 'name' => 'Turno mañana']);
    }

    public function test_a_driver_cannot_publish_an_express_route(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->postJson('/api/v1/express-routes', [
                'name' => 'Turno mañana',
                'origin_lat' => -0.1807,
                'origin_lng' => -78.4678,
                'destination_lat' => -0.2000,
                'destination_lng' => -78.5000,
                'days_of_week' => [1],
                'departure_time' => '07:30',
                'offered_price' => 5,
            ])
            ->assertUnprocessable();

        $this->assertDatabaseMissing('express_routes', ['client_user_id' => $driver->id]);
    }

    public function test_publishing_below_half_the_estimated_price_fails(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();
        DriverProfile::factory()->for($driver)->create(['rate_per_km' => 1]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->postJson('/api/v1/express-routes', [
                'name' => 'Turno mañana',
                'origin_lat' => -0.1807,
                'origin_lng' => -78.4678,
                'destination_lat' => -0.2000,
                'destination_lng' => -78.4800,
                'days_of_week' => [1],
                'departure_time' => '07:30',
                'offered_price' => 0.5,
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('express_routes', 0);
    }

    public function test_mine_lists_the_clients_own_routes_with_reference_rate(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();
        DriverProfile::factory()->for($driver)->create(['rate_per_km' => 0.5]);
        $this->openRoute($client->id);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->getJson('/api/v1/express-routes/mine');

        $response->assertOk()
            ->assertJsonCount(1, 'routes')
            ->assertJsonPath('reference_rate_per_km', 0.5);
    }

    public function test_a_driver_cannot_list_mine(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->getJson('/api/v1/express-routes/mine')
            ->assertForbidden();
    }

    public function test_a_fleet_driver_sees_the_open_route_and_can_apply(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();
        $route = $this->openRoute($client->id);

        $available = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->getJson('/api/v1/express-routes/available');

        $available->assertOk()->assertJsonCount(1, 'routes')->assertJsonPath('routes.0.id', $route->id);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->postJson("/api/v1/express-routes/{$route->id}/applications", ['proposed_price' => 6])
            ->assertCreated();

        $this->assertDatabaseHas('express_applications', [
            'express_route_id' => $route->id,
            'driver_user_id' => $driver->id,
            'proposed_price' => 6,
            'status' => 'pending',
        ]);
    }

    public function test_a_driver_outside_the_fleet_does_not_see_the_route(): void
    {
        [$client] = $this->clientWithFleetDriver();
        $outsider = User::factory()->create();
        DriverProfile::factory()->for($outsider)->create();
        $this->openRoute($client->id);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($outsider))
            ->getJson('/api/v1/express-routes/available')
            ->assertOk()
            ->assertJsonCount(0, 'routes');
    }

    public function test_a_driver_without_the_plan_feature_cannot_apply(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();
        $route = $this->openRoute($client->id);

        $plan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'basico')->firstOrFail();
        $plan->update(['express_enabled' => false]);
        Subscription::factory()->for($driver)->create(['subscription_plan_id' => $plan->id, 'status' => 'active']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->postJson("/api/v1/express-routes/{$route->id}/applications", ['proposed_price' => 6])
            ->assertUnprocessable();

        $this->assertDatabaseCount('express_applications', 0);
    }

    public function test_the_owner_can_see_the_route_detail(): void
    {
        [$client] = $this->clientWithFleetDriver();
        $route = $this->openRoute($client->id);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->getJson("/api/v1/express-routes/{$route->id}")
            ->assertOk()
            ->assertJsonPath('route.id', $route->id);
    }

    public function test_a_stranger_cannot_see_the_route_detail(): void
    {
        [$client] = $this->clientWithFleetDriver();
        $route = $this->openRoute($client->id);
        $stranger = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->getJson("/api/v1/express-routes/{$route->id}")
            ->assertForbidden();
    }

    public function test_a_stranger_cannot_cancel_someone_elses_route(): void
    {
        [$client] = $this->clientWithFleetDriver();
        $route = $this->openRoute($client->id);
        $stranger = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->postJson("/api/v1/express-routes/{$route->id}/cancel")
            ->assertForbidden();
    }

    public function test_the_owner_can_cancel_their_own_route(): void
    {
        [$client] = $this->clientWithFleetDriver();
        $route = $this->openRoute($client->id);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->postJson("/api/v1/express-routes/{$route->id}/cancel")
            ->assertOk()
            ->assertJsonPath('route.status', 'cancelled');
    }

    public function test_the_owner_can_update_an_open_route(): void
    {
        [$client] = $this->clientWithFleetDriver();
        $route = $this->openRoute($client->id);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->putJson("/api/v1/express-routes/{$route->id}", [
                'name' => 'Turno actualizado',
                'departure_time' => '08:00',
                'days_of_week' => [1, 2],
                'offered_price' => 5,
            ]);

        $response->assertOk()->assertJsonPath('route.name', 'Turno actualizado');
    }

    public function test_client_accepting_an_application_activates_the_route_and_rejects_the_rest(): void
    {
        [$client, $driverA, $fleet] = $this->clientWithFleetDriver();
        $driverB = User::factory()->create();
        FleetMember::factory()->for($fleet)->for($driverB, 'driver')->create(['added_by' => $client->id]);

        $route = $this->openRoute($client->id);
        $applicationA = ExpressApplication::query()->create([
            'express_route_id' => $route->id, 'driver_user_id' => $driverA->id, 'status' => 'pending', 'applied_at' => now(),
        ]);
        $applicationB = ExpressApplication::query()->create([
            'express_route_id' => $route->id, 'driver_user_id' => $driverB->id, 'status' => 'pending', 'applied_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->postJson("/api/v1/express-applications/{$applicationA->id}/accept")
            ->assertOk()
            ->assertJsonPath('application.status', 'accepted');

        $this->assertSame('active', $route->fresh()->status);
        $this->assertSame($driverA->id, $route->fresh()->assigned_driver_user_id);
        $this->assertSame('rejected', $applicationB->fresh()->status);
    }

    public function test_a_stranger_cannot_accept_someone_elses_application(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();
        $route = $this->openRoute($client->id);
        $application = ExpressApplication::query()->create([
            'express_route_id' => $route->id, 'driver_user_id' => $driver->id, 'status' => 'pending', 'applied_at' => now(),
        ]);
        $stranger = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->postJson("/api/v1/express-applications/{$application->id}/accept")
            ->assertForbidden();
    }

    public function test_a_driver_can_withdraw_their_own_pending_application(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();
        $route = $this->openRoute($client->id);
        $application = ExpressApplication::query()->create([
            'express_route_id' => $route->id, 'driver_user_id' => $driver->id, 'status' => 'pending', 'applied_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->postJson("/api/v1/express-applications/{$application->id}/withdraw")
            ->assertOk()
            ->assertJsonPath('application.status', 'withdrawn');
    }

    public function test_a_driver_cannot_withdraw_someone_elses_application(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();
        $route = $this->openRoute($client->id);
        $application = ExpressApplication::query()->create([
            'express_route_id' => $route->id, 'driver_user_id' => $driver->id, 'status' => 'pending', 'applied_at' => now(),
        ]);
        $otherDriver = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($otherDriver))
            ->postJson("/api/v1/express-applications/{$application->id}/withdraw")
            ->assertForbidden();
    }

    public function test_it_requires_a_token(): void
    {
        $this->getJson('/api/v1/express-routes/mine')->assertUnauthorized();
        $this->getJson('/api/v1/express-routes/available')->assertUnauthorized();
    }
}
