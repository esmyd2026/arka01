<?php

namespace Tests\Feature\Api\V1;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\Review;
use App\Models\Ride;
use App\Models\User;
use App\Services\PlanLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Flotas de confianza en la app móvil (roadmap Hito 5) — reusa
 * App\Services\Fleet\FleetRosterBuilder, el mismo cálculo que
 * FleetController (web), así que estos tests se enfocan en la forma del
 * JSON y en lo que es específico del canal móvil.
 */
class MobileFleetTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_a_client_without_a_fleet_gets_one_created_automatically(): void
    {
        $client = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->getJson('/api/v1/fleet');

        $response->assertOk()
            ->assertJsonCount(1, 'fleets')
            ->assertJsonPath('fleets.0.name', 'Mi flota');

        $this->assertDatabaseHas('fleets', ['owner_user_id' => $client->id, 'name' => 'Mi flota']);
    }

    public function test_the_fleet_includes_member_stats(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();

        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['rate_per_km' => 0.5, 'is_available' => true]);
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        Review::factory()->create(['reviewee_user_id' => $driver->id, 'rating' => 5]);
        Review::factory()->create(['reviewee_user_id' => $driver->id, 'rating' => 3]);
        Ride::factory()->create(['driver_user_id' => $driver->id, 'status' => 'completed']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->getJson('/api/v1/fleet');

        $response->assertOk()
            ->assertJsonPath('fleets.0.members.0.user_id', $driver->public_id)
            ->assertJsonPath('fleets.0.members.0.driver_user_id', $driver->id)
            ->assertJsonPath('fleets.0.members.0.name', $driver->full_name)
            ->assertJsonPath('fleets.0.members.0.rate_per_km', '0.50')
            ->assertJsonPath('fleets.0.members.0.is_available', true)
            ->assertJsonPath('fleets.0.members.0.average_rating', 4)
            ->assertJsonPath('fleets.0.members.0.review_count', 2)
            ->assertJsonPath('fleets.0.members.0.rides_count', 1);
    }

    public function test_a_driver_cannot_see_a_fleet(): void
    {
        $driver = User::factory()->create(['role' => 'conductor']);
        DriverProfile::factory()->for($driver)->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->getJson('/api/v1/fleet');

        $response->assertForbidden();
    }

    public function test_it_requires_a_token(): void
    {
        $this->getJson('/api/v1/fleet')->assertUnauthorized();
    }

    public function test_it_reports_the_plan_limits(): void
    {
        $client = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->getJson('/api/v1/fleet');

        $response->assertOk()->assertJsonStructure([
            'max_fleets', 'max_drivers_per_fleet', 'max_cooperatives', 'plan_code', 'plan_name',
        ]);
    }

    public function test_a_client_can_search_drivers_by_member_code(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();

        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->getJson("/api/v1/fleet/{$fleet->id}/search-drivers?q={$driver->member_code}");

        $response->assertOk()
            ->assertJsonCount(1, 'drivers')
            ->assertJsonPath('drivers.0.user_id', $driver->id)
            ->assertJsonPath('drivers.0.status', 'not_invited');
    }

    public function test_a_stranger_cannot_search_another_clients_fleet(): void
    {
        $owner = User::factory()->create();
        $fleet = Fleet::factory()->for($owner, 'owner')->create();
        $stranger = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->getJson("/api/v1/fleet/{$fleet->id}/search-drivers?q=algo");

        $response->assertForbidden();
    }

    public function test_a_client_can_invite_a_driver(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = User::factory()->create();
        // Este test verifica el status 'pending' de la invitación en sí — la
        // aprobación automática (default) no es lo que se está probando acá.
        DriverProfile::factory()->for($driver)->create(['requires_fleet_invitation_approval' => true]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->postJson("/api/v1/fleet/{$fleet->id}/invitations", ['driver_user_id' => $driver->id]);

        $response->assertCreated()->assertJsonPath('status', 'pending');
        $this->assertDatabaseHas('fleet_invitations', [
            'fleet_id' => $fleet->id,
            'driver_user_id' => $driver->id,
            'status' => 'pending',
        ]);
    }

    public function test_inviting_past_the_plan_limit_is_rejected(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();

        $maxDriversPerFleet = app(PlanLimits::class)->forClient($client)['max_drivers_per_fleet'];
        if ($maxDriversPerFleet === null) {
            $this->markTestSkipped('El plan gratis no tiene límite de conductores por flota en este entorno.');
        }

        for ($i = 0; $i < $maxDriversPerFleet; $i++) {
            $existingDriver = User::factory()->create();
            DriverProfile::factory()->for($existingDriver)->create();
            FleetMember::factory()->for($fleet)->for($existingDriver, 'driver')->create(['added_by' => $client->id]);
        }

        $newDriver = User::factory()->create();
        DriverProfile::factory()->for($newDriver)->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->postJson("/api/v1/fleet/{$fleet->id}/invitations", ['driver_user_id' => $newDriver->id]);

        $response->assertUnprocessable();
    }

    public function test_a_client_can_remove_a_driver_from_the_fleet(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $member = FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->deleteJson("/api/v1/fleet/members/{$member->id}");

        $response->assertOk();
        $this->assertNotNull($member->fresh()->left_at);
    }

    public function test_a_stranger_cannot_remove_a_member_from_someone_elses_fleet(): void
    {
        $owner = User::factory()->create();
        $fleet = Fleet::factory()->for($owner, 'owner')->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $member = FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $owner->id]);

        $stranger = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->deleteJson("/api/v1/fleet/members/{$member->id}");

        $response->assertForbidden();
        $this->assertNull($member->fresh()->left_at);
    }
}
