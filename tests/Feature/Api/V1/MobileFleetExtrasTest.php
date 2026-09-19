<?php

namespace Tests\Feature\Api\V1;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetInvitation;
use App\Models\FleetMember;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Huecos de "Mi Flota" desde la app móvil (roadmap Hito 5B, paridad con la
 * web): crear una flota adicional, ver el detalle de una puntual, cancelar
 * una invitación, y "recomendar mi flota" a un amigo. Reusa los mismos
 * servicios que la web (tests\Feature\Fleet\MultiFleetTest,
 * tests\Feature\Fleet\FleetReferralTest) — estos casos se enfocan en el
 * contrato JSON del canal móvil.
 */
class MobileFleetExtrasTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_a_client_can_create_an_additional_fleet_on_a_plan_that_allows_it(): void
    {
        $client = User::factory()->create();
        Subscription::factory()->for($client)->create([
            'subscription_plan_id' => SubscriptionPlan::where('owner_type', 'client')->where('code', 'multiflota')->firstOrFail()->id,
            'status' => 'active',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->postJson('/api/v1/fleet', ['name' => 'Segunda flota']);

        $response->assertCreated()->assertJsonPath('fleet.name', 'Segunda flota');
        $this->assertDatabaseHas('fleets', ['owner_user_id' => $client->id, 'name' => 'Segunda flota']);
    }

    public function test_a_client_cannot_create_a_second_fleet_on_the_free_plan(): void
    {
        $client = User::factory()->create();
        Fleet::factory()->for($client, 'owner')->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->postJson('/api/v1/fleet', ['name' => 'Otra'])
            ->assertUnprocessable();
    }

    public function test_a_driver_cannot_create_a_fleet(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->postJson('/api/v1/fleet', ['name' => 'x'])
            ->assertUnprocessable();
    }

    public function test_a_client_can_see_a_specific_fleets_detail(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->getJson("/api/v1/fleet/{$fleet->id}")
            ->assertOk()
            ->assertJsonPath('fleet.id', $fleet->id);
    }

    public function test_a_stranger_cannot_view_someone_elses_fleet(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $stranger = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->getJson("/api/v1/fleet/{$fleet->id}")
            ->assertForbidden();
    }

    public function test_client_can_search_a_friend_by_member_code(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $friend = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->getJson("/api/v1/fleet/{$fleet->id}/search-friends?q=".$friend->member_code);

        $response->assertOk()->assertJsonPath('friends.0.user_id', $friend->id);
    }

    public function test_client_can_refer_all_drivers_of_their_fleet_to_a_friend(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);
        $friend = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->postJson("/api/v1/fleet/{$fleet->id}/referral", [
                'friend_user_id' => $friend->id,
                'driver_user_ids' => [$driver->id],
            ]);

        $response->assertCreated()->assertJsonPath('sent', 1);
        $friendFleet = Fleet::where('owner_user_id', $friend->id)->firstOrFail();
        $this->assertDatabaseHas('fleet_invitations', [
            'fleet_id' => $friendFleet->id,
            'driver_user_id' => $driver->id,
            'initiated_by' => 'referral',
        ]);
    }

    public function test_cannot_refer_a_driver_that_is_not_in_my_fleet(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $outsideDriver = User::factory()->create();
        DriverProfile::factory()->for($outsideDriver)->create();
        $friend = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->postJson("/api/v1/fleet/{$fleet->id}/referral", [
                'friend_user_id' => $friend->id,
                'driver_user_ids' => [$outsideDriver->id],
            ])
            ->assertUnprocessable();
    }

    public function test_the_inviter_can_cancel_a_pending_invitation(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $invitation = FleetInvitation::query()->create([
            'fleet_id' => $fleet->id,
            'driver_user_id' => $driver->id,
            'invited_by' => $client->id,
            'initiated_by' => 'client',
            'status' => 'pending',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->deleteJson("/api/v1/fleet/invitations/{$invitation->id}")
            ->assertOk();

        $this->assertSame('cancelled', $invitation->fresh()->status);
    }

    public function test_a_stranger_cannot_cancel_someone_elses_invitation(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $invitation = FleetInvitation::query()->create([
            'fleet_id' => $fleet->id,
            'driver_user_id' => $driver->id,
            'invited_by' => $client->id,
            'initiated_by' => 'client',
            'status' => 'pending',
        ]);
        $stranger = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->deleteJson("/api/v1/fleet/invitations/{$invitation->id}")
            ->assertForbidden();
    }

    public function test_it_requires_a_token(): void
    {
        $this->postJson('/api/v1/fleet', ['name' => 'x'])->assertUnauthorized();
    }
}
