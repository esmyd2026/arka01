<?php

namespace Tests\Feature\Api\V1;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ciclo de vida de una carrera ya aceptada, desde la app móvil (roadmap
 * Hito 5) — reusa App\Services\Ride\RideLifecycle, el mismo servicio que ya
 * cubre tests/Feature/Ride/RideRequestFlowTest.php y
 * RideTrackingBroadcastTest.php, así que estos tests se enfocan en el
 * contrato JSON y en lo específico del canal móvil.
 */
class MobileRideTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function inProgressRide(array $overrides = []): Ride
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $rideRequest = RideRequest::factory()->for($client, 'client')->for($driver, 'driver')->for($fleet)->create([
            'status' => 'accepted',
        ]);

        return Ride::factory()
            ->for($rideRequest)
            ->for($fleet)
            ->for($client, 'client')
            ->for($driver, 'driver')
            ->create(array_merge(['status' => 'in_progress'], $overrides));
    }

    public function test_a_client_can_see_their_active_ride(): void
    {
        $ride = $this->inProgressRide();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($ride->client))
            ->getJson('/api/v1/rides/active');

        $response->assertOk()
            ->assertJsonPath('ride.id', $ride->id)
            ->assertJsonPath('ride.is_driver', false);
    }

    public function test_a_driver_can_see_their_active_ride(): void
    {
        $ride = $this->inProgressRide();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($ride->driver))
            ->getJson('/api/v1/rides/active');

        $response->assertOk()
            ->assertJsonPath('ride.id', $ride->id)
            ->assertJsonPath('ride.is_driver', true);
    }

    public function test_a_user_with_no_active_ride_gets_null(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/v1/rides/active');

        $response->assertOk()->assertJsonPath('ride', null);
    }

    public function test_a_stranger_cannot_view_someone_elses_ride(): void
    {
        $ride = $this->inProgressRide();
        $stranger = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->getJson("/api/v1/rides/{$ride->id}")
            ->assertForbidden();
    }

    public function test_the_driver_can_mark_heading_to_passenger(): void
    {
        $ride = $this->inProgressRide();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($ride->driver))
            ->postJson("/api/v1/rides/{$ride->id}/heading-to-passenger");

        $response->assertOk();
        $this->assertNotNull($ride->fresh()->heading_to_passenger_at);
    }

    public function test_the_driver_can_mark_arrived(): void
    {
        $ride = $this->inProgressRide();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($ride->driver))
            ->postJson("/api/v1/rides/{$ride->id}/arrived");

        $response->assertOk();
        $this->assertNotNull($ride->fresh()->arrived_at);
    }

    public function test_arriving_far_from_the_pickup_point_is_rejected(): void
    {
        $ride = $this->inProgressRide();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($ride->driver))
            ->postJson("/api/v1/rides/{$ride->id}/arrived", [
                'lat' => (float) $ride->origin_lat + 1,
                'lng' => (float) $ride->origin_lng + 1,
            ]);

        $response->assertUnprocessable();
        $this->assertNull($ride->fresh()->arrived_at);
    }

    public function test_the_client_cannot_mark_arrived(): void
    {
        $ride = $this->inProgressRide();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($ride->client))
            ->postJson("/api/v1/rides/{$ride->id}/arrived")
            ->assertForbidden();
    }

    public function test_the_driver_can_mark_picked_up(): void
    {
        $ride = $this->inProgressRide();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($ride->driver))
            ->postJson("/api/v1/rides/{$ride->id}/picked-up");

        $response->assertOk();
        $this->assertNotNull($ride->fresh()->picked_up_at);
    }

    public function test_the_driver_can_complete_the_ride_near_the_destination(): void
    {
        $ride = $this->inProgressRide();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($ride->driver))
            ->postJson("/api/v1/rides/{$ride->id}/complete", [
                'lat' => (float) $ride->destination_lat,
                'lng' => (float) $ride->destination_lng,
            ]);

        $response->assertOk()->assertJsonPath('ride.status', 'completed');
        $this->assertSame('completed', $ride->fresh()->status);
    }

    public function test_completing_far_from_the_destination_requires_a_reason(): void
    {
        $ride = $this->inProgressRide();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($ride->driver))
            ->postJson("/api/v1/rides/{$ride->id}/complete", [
                'lat' => (float) $ride->destination_lat + 1,
                'lng' => (float) $ride->destination_lng + 1,
            ]);

        $response->assertUnprocessable();
    }

    public function test_the_driver_can_cancel_the_ride_with_a_valid_reason(): void
    {
        $ride = $this->inProgressRide();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($ride->driver))
            ->postJson("/api/v1/rides/{$ride->id}/cancel", ['reason' => 'Problema con el vehículo']);

        $response->assertOk()->assertJsonPath('ride.status', 'cancelled');
        $this->assertSame('driver', $ride->fresh()->cancelled_by);
    }

    public function test_the_client_can_cancel_the_ride_with_a_client_reason(): void
    {
        $ride = $this->inProgressRide();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($ride->client))
            ->postJson("/api/v1/rides/{$ride->id}/cancel", ['reason' => 'Cambié de planes']);

        $response->assertOk();
        $this->assertSame('client', $ride->fresh()->cancelled_by);
    }

    public function test_the_driver_cannot_cancel_with_a_client_only_reason(): void
    {
        $ride = $this->inProgressRide();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($ride->driver))
            ->postJson("/api/v1/rides/{$ride->id}/cancel", ['reason' => 'Cambié de planes'])
            ->assertUnprocessable();
    }

    public function test_a_stranger_cannot_cancel_the_ride(): void
    {
        $ride = $this->inProgressRide();
        $stranger = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->postJson("/api/v1/rides/{$ride->id}/cancel", ['reason' => 'Otro motivo'])
            ->assertForbidden();
    }

    public function test_the_driver_can_update_their_location_during_the_ride(): void
    {
        $ride = $this->inProgressRide();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($ride->driver))
            ->postJson("/api/v1/rides/{$ride->id}/location", ['lat' => -2.19, 'lng' => -79.89]);

        $response->assertOk();
        $this->assertDatabaseHas('driver_profiles', [
            'user_id' => $ride->driver_user_id,
            'current_lat' => -2.19,
            'current_lng' => -79.89,
        ]);
    }

    public function test_location_updates_are_rejected_after_the_ride_finishes(): void
    {
        $ride = $this->inProgressRide(['status' => 'completed', 'completed_at' => now()]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($ride->driver))
            ->postJson("/api/v1/rides/{$ride->id}/location", ['lat' => -2.19, 'lng' => -79.89])
            ->assertUnprocessable();
    }

    public function test_it_requires_a_token(): void
    {
        $this->getJson('/api/v1/rides/active')->assertUnauthorized();
    }
}
