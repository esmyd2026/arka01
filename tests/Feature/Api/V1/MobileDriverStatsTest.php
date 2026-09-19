<?php

namespace Tests\Feature\Api\V1;

use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Mis indicadores" del conductor y enlace de seguimiento desde la app
 * móvil (roadmap Hito 5B, paridad con la web). Reusa
 * App\Services\Driver\DriverStatsFinder — mismo servicio que
 * tests\Feature\DriverStatsControllerTest y
 * tests\Feature\Security\RideTrackingTest (para tracking-link).
 */
class MobileDriverStatsTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_a_client_cannot_access_the_stats_screen(): void
    {
        $client = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->getJson('/api/v1/driver/stats')
            ->assertForbidden();
    }

    public function test_the_cards_total_only_this_drivers_rides(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        Ride::factory()->create(['driver_user_id' => $driver->id, 'status' => 'completed', 'price' => 10]);
        Ride::factory()->create(['driver_user_id' => $driver->id, 'status' => 'completed', 'price' => 5]);
        Ride::factory()->create(['status' => 'completed', 'price' => 999]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->getJson('/api/v1/driver/stats');

        $response->assertOk()
            ->assertJsonPath('totals.completed', 2)
            ->assertJsonPath('totals.earnings', 15);
    }

    public function test_it_requires_a_token(): void
    {
        $this->getJson('/api/v1/driver/stats')->assertUnauthorized();
    }

    public function test_a_ride_participant_can_generate_a_tracking_link(): void
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();
        $ride = Ride::factory()->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'in_progress',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->getJson("/api/v1/rides/{$ride->id}/tracking-link");

        $response->assertOk();
        $this->assertStringContainsString('/seguimiento/'.$ride->public_id, $response->json('url'));
    }

    public function test_a_stranger_cannot_generate_a_tracking_link(): void
    {
        $ride = Ride::factory()->create(['status' => 'in_progress']);
        $stranger = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->getJson("/api/v1/rides/{$ride->id}/tracking-link")
            ->assertForbidden();
    }
}
