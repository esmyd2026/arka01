<?php

namespace Tests\Feature\Express;

use App\Console\Commands\GenerateExpressRides;
use App\Models\ExpressRoute;
use App\Models\ExpressRouteCompanion;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\RideRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Pedido explícito del usuario: hoy un conductor no le conviene hacer un
 * Expreso porque va vacío a buscar a una sola persona por una sola carrera —
 * si el dueño se abre a que otros clientes con ruta/horario parecido se
 * sumen, el precio total pactado se reparte entre más gente y el viaje sí le
 * conviene al conductor.
 */
class ExpressRouteSharingTest extends TestCase
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

    public function test_a_client_can_publish_a_route_open_to_sharing(): void
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
            'offered_price' => 10,
            'share_enabled' => true,
            'max_companions' => 2,
        ])->assertRedirect();

        $this->assertDatabaseHas('express_routes', ['name' => 'Turno mañana', 'share_enabled' => true, 'max_companions' => 2]);
    }

    public function test_discover_finds_a_nearby_shareable_route(): void
    {
        [$owner] = $this->clientWithFleetDriver();
        $route = $this->shareableRoute($owner);

        $seeker = User::factory()->create();

        $response = $this->actingAs($seeker)->get(route('express-companions.discover', [
            'origin_lat' => -0.181, 'origin_lng' => -78.468,
            'destination_lat' => -0.201, 'destination_lng' => -78.501,
        ]));

        $response->assertInertia(fn ($page) => $page
            ->component('Express/Discover')
            ->has('routes', 1)
            ->where('routes.0.id', $route->id)
        );
    }

    public function test_discover_excludes_a_route_that_is_far_from_the_searched_route(): void
    {
        [$owner] = $this->clientWithFleetDriver();
        // Guayaquil, bien lejos de las coordenadas de Quito que busca el seeker.
        $this->shareableRoute($owner, ['origin_lat' => -2.20, 'origin_lng' => -79.90, 'destination_lat' => -2.15, 'destination_lng' => -79.88]);

        $seeker = User::factory()->create();

        $response = $this->actingAs($seeker)->get(route('express-companions.discover', [
            'origin_lat' => -0.181, 'origin_lng' => -78.468,
            'destination_lat' => -0.201, 'destination_lng' => -78.501,
        ]));

        $response->assertInertia(fn ($page) => $page->has('routes', 0));
    }

    public function test_discover_excludes_routes_that_are_not_open_to_sharing(): void
    {
        [$owner] = $this->clientWithFleetDriver();
        $this->shareableRoute($owner, ['share_enabled' => false]);

        $seeker = User::factory()->create();

        $response = $this->actingAs($seeker)->get(route('express-companions.discover', [
            'origin_lat' => -0.181, 'origin_lng' => -78.468,
            'destination_lat' => -0.201, 'destination_lng' => -78.501,
        ]));

        $response->assertInertia(fn ($page) => $page->has('routes', 0));
    }

    public function test_a_client_can_request_to_join_a_shareable_route(): void
    {
        [$owner] = $this->clientWithFleetDriver();
        $route = $this->shareableRoute($owner);

        $seeker = User::factory()->create();

        $this->actingAs($seeker)->post(route('express-companions.store', $route))->assertRedirect();

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

        $this->actingAs($owner)->post(route('express-companions.store', $route))->assertForbidden();
    }

    public function test_cannot_request_to_join_a_route_that_is_full(): void
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

        $this->actingAs($seeker)
            ->post(route('express-companions.store', $route))
            ->assertSessionHasErrors('route');
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

        $this->actingAs($owner)->post(route('express-companions.accept', $companion))->assertRedirect();

        $this->assertSame('accepted', $companion->fresh()->status);
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

        $this->actingAs($stranger)->post(route('express-companions.accept', $companion))->assertForbidden();
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

        $this->actingAs($seeker)->post(route('express-companions.leave', $companion))->assertRedirect();

        $this->assertSame('left', $companion->fresh()->status);
    }

    public function test_price_per_person_splits_the_total_between_owner_and_accepted_companions(): void
    {
        [$owner] = $this->clientWithFleetDriver();
        $route = $this->shareableRoute($owner, ['offered_price' => 9]);

        $this->assertSame(9.0, $route->pricePerPerson());

        $companion = User::factory()->create();
        ExpressRouteCompanion::query()->create([
            'express_route_id' => $route->id,
            'passenger_user_id' => $companion->id,
            'status' => 'accepted',
            'requested_at' => now(),
            'responded_at' => now(),
        ]);

        $this->assertSame(4.5, $route->fresh()->pricePerPerson());
    }

    public function test_generating_express_rides_also_creates_a_ride_request_for_each_accepted_companion(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 00:00:00')); // lunes

        [$owner, $driver] = $this->clientWithFleetDriver();

        $route = $this->shareableRoute($owner, [
            'days_of_week' => [1],
            'offered_price' => 8,
            'status' => 'active',
            'assigned_driver_user_id' => $driver->id,
            'assigned_at' => now(),
        ]);

        $companion = User::factory()->create();
        ExpressRouteCompanion::query()->create([
            'express_route_id' => $route->id,
            'passenger_user_id' => $companion->id,
            'status' => 'accepted',
            'requested_at' => now(),
            'responded_at' => now(),
        ]);

        $this->artisan(GenerateExpressRides::class)->assertSuccessful();

        $this->assertDatabaseHas('ride_requests', [
            'express_route_id' => $route->id,
            'client_user_id' => $owner->id,
            'driver_user_id' => $driver->id,
            'current_offered_price' => 4.0,
        ]);

        $this->assertDatabaseHas('ride_requests', [
            'express_route_id' => $route->id,
            'client_user_id' => $companion->id,
            'driver_user_id' => $driver->id,
            'current_offered_price' => 4.0,
        ]);

        $this->assertSame(2, RideRequest::where('express_route_id', $route->id)->count());
    }
}
