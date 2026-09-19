<?php

namespace Tests\Feature\Api\V1;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetInvitation;
use App\Models\FleetMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Mis clientes de confianza" (lado conductor) desde la app móvil (roadmap
 * Hito 5B, paridad con la web). Reusa App\Services\Driver\DriverClientFinder
 * y App\Services\Fleet\FleetInvitationManager — mismos servicios que
 * tests\Feature\Fleet\DriverInitiatedFleetInvitationTest y
 * tests\Feature\Fleet\DisableClientRequestsTest.
 */
class MobileDriverInvitationTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_driver_can_search_clients_by_member_code(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $client = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->getJson('/api/v1/driver/clients/search?q='.$client->member_code);

        $response->assertOk()
            ->assertJsonPath('clients.0.user_id', $client->id)
            ->assertJsonPath('clients.0.status', 'not_invited');
    }

    public function test_the_index_lists_pending_invitations_and_active_memberships(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->getJson('/api/v1/driver/invitations');

        $response->assertOk()->assertJsonCount(1, 'active_memberships');
    }

    public function test_driver_can_send_a_join_request_creating_a_fleet_for_a_client_without_one(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $client = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->postJson('/api/v1/driver/invitations', ['client_user_id' => $client->id]);

        $response->assertCreated();
        $this->assertDatabaseHas('fleet_invitations', [
            'driver_user_id' => $driver->id,
            'initiated_by' => 'driver',
            'status' => 'pending',
        ]);
    }

    public function test_client_can_accept_a_driver_initiated_request(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $invitation = FleetInvitation::query()->create([
            'fleet_id' => $fleet->id,
            'driver_user_id' => $driver->id,
            'invited_by' => $driver->id,
            'initiated_by' => 'driver',
            'status' => 'pending',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->postJson("/api/v1/driver/invitations/{$invitation->id}/accept")
            ->assertOk();

        $this->assertTrue(
            FleetMember::where('fleet_id', $fleet->id)->where('driver_user_id', $driver->id)->whereNull('left_at')->exists()
        );
    }

    public function test_driver_cannot_accept_their_own_request(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $invitation = FleetInvitation::query()->create([
            'fleet_id' => $fleet->id,
            'driver_user_id' => $driver->id,
            'invited_by' => $driver->id,
            'initiated_by' => 'driver',
            'status' => 'pending',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->postJson("/api/v1/driver/invitations/{$invitation->id}/accept")
            ->assertForbidden();
    }

    public function test_driver_can_leave_a_fleet_voluntarily(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $member = FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->postJson("/api/v1/driver/fleets/{$member->id}/leave")
            ->assertOk();

        $this->assertNotNull($member->fresh()->left_at);
    }

    public function test_the_driver_can_toggle_requests_off_and_on_for_a_client(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $member = FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);
        $token = $this->tokenFor($driver);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/driver/fleets/{$member->id}/toggle-requests")
            ->assertOk()
            ->assertJsonPath('requests_disabled', true);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/driver/fleets/{$member->id}/toggle-requests")
            ->assertOk()
            ->assertJsonPath('requests_disabled', false);
    }

    public function test_a_stranger_cannot_toggle_someone_elses_membership(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $member = FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);
        $stranger = User::factory()->create();
        DriverProfile::factory()->for($stranger)->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->postJson("/api/v1/driver/fleets/{$member->id}/toggle-requests")
            ->assertForbidden();
    }

    public function test_it_requires_a_token(): void
    {
        $this->getJson('/api/v1/driver/invitations')->assertUnauthorized();
    }
}
