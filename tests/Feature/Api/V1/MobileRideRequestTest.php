<?php

namespace Tests\Feature\Api\V1;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Solicitar carrera desde la app móvil (roadmap Hito 5) — reusa
 * App\Services\Ride\RideRequestCreator y RideRequestResponder, los mismos
 * servicios que ya cubre tests/Feature/Ride/RideRequestFlowTest.php, así
 * que estos tests se enfocan en la forma del JSON y en lo específico del
 * canal móvil (autorización, contrato de la respuesta), no en repetir cada
 * regla de negocio de despacho/precio ya probada allá.
 */
class MobileRideRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-01-15 12:00:00'));
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function clientWithFleetDriver(): array
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['rate_per_km' => 0.50, 'is_available' => true]);

        $fleet = Fleet::factory()->for($client, 'owner')->create();
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        return [$client, $driver, $fleet];
    }

    public function test_a_client_can_request_an_immediate_ride_directed_to_a_fleet_driver(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->postJson('/api/v1/ride-requests', [
                'driver_user_id' => $driver->id,
                'origin_lat' => -2.1943,
                'origin_lng' => -79.8920,
                'destination_lat' => -2.1837,
                'destination_lng' => -79.9032,
            ]);

        $response->assertCreated()
            ->assertJsonPath('ride_request.status', 'pending')
            ->assertJsonPath('ride_request.driver.user_id', $driver->public_id);

        $this->assertDatabaseHas('ride_requests', [
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'pending',
        ]);
    }

    public function test_a_client_can_request_an_immediate_ride_dispatched_to_the_whole_fleet(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->postJson('/api/v1/ride-requests', [
                'dispatch_pool' => 'fleet',
                'origin_lat' => -2.1943,
                'origin_lng' => -79.8920,
                'destination_lat' => -2.1837,
                'destination_lng' => -79.9032,
            ]);

        $response->assertCreated()->assertJsonPath('ride_request.status', 'pending');

        $this->assertDatabaseHas('ride_requests', [
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'dispatch_pool' => 'fleet',
        ]);
    }

    public function test_a_driver_cannot_request_a_ride(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->postJson('/api/v1/ride-requests', [
                'origin_lat' => -2.1943,
                'origin_lng' => -79.8920,
                'destination_lat' => -2.1837,
                'destination_lng' => -79.9032,
            ]);

        $response->assertUnprocessable();
    }

    public function test_it_requires_a_token(): void
    {
        $this->postJson('/api/v1/ride-requests', [])->assertUnauthorized();
        $this->getJson('/api/v1/ride-requests')->assertUnauthorized();
    }

    public function test_a_client_cannot_request_a_second_immediate_ride_while_one_is_active(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        RideRequest::factory()->for($client, 'client')->create([
            'is_scheduled' => false,
            'status' => 'pending',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->postJson('/api/v1/ride-requests', [
                'driver_user_id' => $driver->id,
                'origin_lat' => -2.1943,
                'origin_lng' => -79.8920,
                'destination_lat' => -2.1837,
                'destination_lng' => -79.9032,
            ]);

        $response->assertUnprocessable();
    }

    public function test_a_client_can_view_their_active_ride_requests(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $rideRequest = RideRequest::factory()->for($client, 'client')->for($driver, 'driver')->create([
            'status' => 'pending',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->getJson('/api/v1/ride-requests');

        $response->assertOk()
            ->assertJsonCount(1, 'ride_requests')
            ->assertJsonPath('ride_requests.0.id', $rideRequest->id);
    }

    public function test_a_client_can_view_a_single_ride_requests_status(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $rideRequest = RideRequest::factory()->for($client, 'client')->for($driver, 'driver')->create([
            'status' => 'pending',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->getJson("/api/v1/ride-requests/{$rideRequest->id}");

        $response->assertOk()->assertJsonPath('ride_request.id', $rideRequest->id);
    }

    public function test_a_stranger_cannot_view_someone_elses_ride_request(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();
        $stranger = User::factory()->create();

        $rideRequest = RideRequest::factory()->for($client, 'client')->for($driver, 'driver')->create([
            'status' => 'pending',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->getJson("/api/v1/ride-requests/{$rideRequest->id}")
            ->assertForbidden();
    }

    public function test_a_client_can_cancel_a_pending_ride_request(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $rideRequest = RideRequest::factory()->for($client, 'client')->for($driver, 'driver')->create([
            'status' => 'pending',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->postJson("/api/v1/ride-requests/{$rideRequest->id}/cancel");

        $response->assertOk();
        $this->assertSame('cancelled', $rideRequest->fresh()->status);
    }

    public function test_a_stranger_cannot_cancel_someone_elses_ride_request(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();
        $stranger = User::factory()->create();

        $rideRequest = RideRequest::factory()->for($client, 'client')->for($driver, 'driver')->create([
            'status' => 'pending',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->postJson("/api/v1/ride-requests/{$rideRequest->id}/cancel")
            ->assertForbidden();

        $this->assertSame('pending', $rideRequest->fresh()->status);
    }

    public function test_a_driver_can_view_incoming_requests_directed_to_them(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $rideRequest = RideRequest::factory()->for($client, 'client')->for($driver, 'driver')->create([
            'status' => 'pending',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->getJson('/api/v1/ride-requests/incoming');

        $response->assertOk()
            ->assertJsonCount(1, 'ride_requests')
            ->assertJsonPath('ride_requests.0.id', $rideRequest->id)
            ->assertJsonPath('ride_requests.0.client.name', $client->full_name);
    }

    public function test_a_driver_can_accept_an_incoming_request(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $rideRequest = RideRequest::factory()->for($client, 'client')->for($driver, 'driver')->create([
            'status' => 'pending',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->postJson("/api/v1/ride-requests/{$rideRequest->id}/accept");

        $response->assertCreated()->assertJsonPath('ride.is_driver', true);

        $this->assertDatabaseHas('rides', [
            'ride_request_id' => $rideRequest->id,
            'driver_user_id' => $driver->id,
            'status' => 'in_progress',
        ]);
    }

    public function test_a_driver_can_reject_a_directed_request(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $rideRequest = RideRequest::factory()->for($client, 'client')->for($driver, 'driver')->create([
            'status' => 'pending',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->postJson("/api/v1/ride-requests/{$rideRequest->id}/reject");

        $response->assertOk();
        $this->assertSame('cancelled', $rideRequest->fresh()->status);
    }

    public function test_a_stranger_cannot_accept_someone_elses_incoming_request(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();
        $stranger = User::factory()->create();
        DriverProfile::factory()->for($stranger)->create();

        $rideRequest = RideRequest::factory()->for($client, 'client')->for($driver, 'driver')->create([
            'status' => 'pending',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->postJson("/api/v1/ride-requests/{$rideRequest->id}/accept")
            ->assertForbidden();
    }
}
