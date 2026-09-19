<?php

namespace Tests\Feature\Api\V1;

use App\Models\Cooperative;
use App\Models\CooperativeDriverMembership;
use App\Models\DriverProfile;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Invitaciones de cooperativas al conductor desde la app móvil (roadmap
 * Hito 5B, paridad con la web). Reusa
 * App\Services\Cooperative\CooperativeDriverResponder — mismo servicio que
 * tests\Feature\CooperativeModuleTest (web).
 */
class MobileCooperativeDriverInvitationTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_a_driver_can_list_their_pending_invitations(): void
    {
        $cooperativeUser = User::factory()->create();
        $cooperative = Cooperative::query()->create(['user_id' => $cooperativeUser->id, 'name' => 'Coop Norte']);
        $cooperative->forceFill(['status' => 'approved'])->save();

        $driver = User::factory()->create();
        DriverProfile::factory()->create(['user_id' => $driver->id, 'driver_type' => 'independent']);
        $membership = CooperativeDriverMembership::query()->create([
            'cooperative_id' => $cooperative->id,
            'driver_user_id' => $driver->id,
            'invited_by_user_id' => $cooperativeUser->id,
            'status' => 'pending',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->getJson('/api/v1/cooperative-driver-invitations');

        $response->assertOk()
            ->assertJsonCount(1, 'memberships')
            ->assertJsonPath('memberships.0.id', $membership->id);
    }

    public function test_a_client_cannot_list_cooperative_invitations(): void
    {
        $client = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->getJson('/api/v1/cooperative-driver-invitations')
            ->assertForbidden();
    }

    public function test_a_driver_can_accept_an_invitation(): void
    {
        $cooperativeUser = User::factory()->create();
        $cooperative = Cooperative::query()->create(['user_id' => $cooperativeUser->id, 'name' => 'Coop Uno']);
        $cooperative->forceFill(['status' => 'approved'])->save();

        $driver = User::factory()->create();
        DriverProfile::factory()->create(['user_id' => $driver->id, 'driver_type' => 'independent']);
        $membership = CooperativeDriverMembership::query()->create([
            'cooperative_id' => $cooperative->id,
            'driver_user_id' => $driver->id,
            'invited_by_user_id' => $cooperativeUser->id,
            'status' => 'pending',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->postJson("/api/v1/cooperative-driver-invitations/{$membership->id}/respond", ['decision' => 'accept'])
            ->assertOk();

        $this->assertSame('accepted', $membership->fresh()->status);
        $this->assertSame('public_transport', $driver->driverProfile->fresh()->driver_type);
    }

    public function test_a_driver_cannot_accept_a_second_cooperative_while_already_active_in_another(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->create(['user_id' => $driver->id, 'driver_type' => 'public_transport']);

        $firstCooperativeUser = User::factory()->create();
        $firstCooperative = Cooperative::query()->create(['user_id' => $firstCooperativeUser->id, 'name' => 'Coop Actual']);
        $firstCooperative->forceFill(['status' => 'approved'])->save();
        CooperativeDriverMembership::query()->create([
            'cooperative_id' => $firstCooperative->id,
            'driver_user_id' => $driver->id,
            'invited_by_user_id' => $firstCooperativeUser->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);

        $secondCooperativeUser = User::factory()->create();
        $secondCooperative = Cooperative::query()->create(['user_id' => $secondCooperativeUser->id, 'name' => 'Coop Nueva']);
        $secondCooperative->forceFill(['status' => 'approved'])->save();
        $pending = CooperativeDriverMembership::query()->create([
            'cooperative_id' => $secondCooperative->id,
            'driver_user_id' => $driver->id,
            'invited_by_user_id' => $secondCooperativeUser->id,
            'status' => 'pending',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->postJson("/api/v1/cooperative-driver-invitations/{$pending->id}/respond", ['decision' => 'accept'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('membership');
    }

    /**
     * Pedido explícito del usuario: el admin puede habilitar por plan que
     * un conductor acepte solicitudes de MÁS de una cooperativa a la vez —
     * ver SubscriptionPlan.multi_cooperative_enabled y
     * PlanLimits::forDriver().
     */
    public function test_a_driver_on_a_multi_cooperative_plan_can_accept_a_second_active_cooperative(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->create(['user_id' => $driver->id, 'driver_type' => 'public_transport']);

        $plan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'plus')->firstOrFail();
        $plan->update(['multi_cooperative_enabled' => true]);
        Subscription::factory()->for($driver)->create(['subscription_plan_id' => $plan->id, 'status' => 'active']);

        $firstCooperativeUser = User::factory()->create();
        $firstCooperative = Cooperative::query()->create(['user_id' => $firstCooperativeUser->id, 'name' => 'Coop Actual']);
        $firstCooperative->forceFill(['status' => 'approved'])->save();
        CooperativeDriverMembership::query()->create([
            'cooperative_id' => $firstCooperative->id,
            'driver_user_id' => $driver->id,
            'invited_by_user_id' => $firstCooperativeUser->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);

        $secondCooperativeUser = User::factory()->create();
        $secondCooperative = Cooperative::query()->create(['user_id' => $secondCooperativeUser->id, 'name' => 'Coop Nueva']);
        $secondCooperative->forceFill(['status' => 'approved'])->save();
        $pending = CooperativeDriverMembership::query()->create([
            'cooperative_id' => $secondCooperative->id,
            'driver_user_id' => $driver->id,
            'invited_by_user_id' => $secondCooperativeUser->id,
            'status' => 'pending',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->postJson("/api/v1/cooperative-driver-invitations/{$pending->id}/respond", ['decision' => 'accept'])
            ->assertOk();

        $this->assertSame('accepted', $pending->fresh()->status);
        $this->assertSame(
            2,
            CooperativeDriverMembership::query()->where('driver_user_id', $driver->id)->where('status', 'accepted')->count()
        );
    }

    public function test_a_driver_cannot_respond_to_someone_elses_invitation(): void
    {
        $cooperativeUser = User::factory()->create();
        $cooperative = Cooperative::query()->create(['user_id' => $cooperativeUser->id, 'name' => 'Coop Uno']);
        $cooperative->forceFill(['status' => 'approved'])->save();

        $owner = User::factory()->create();
        DriverProfile::factory()->create(['user_id' => $owner->id]);
        $membership = CooperativeDriverMembership::query()->create([
            'cooperative_id' => $cooperative->id,
            'driver_user_id' => $owner->id,
            'invited_by_user_id' => $cooperativeUser->id,
            'status' => 'pending',
        ]);

        $stranger = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->postJson("/api/v1/cooperative-driver-invitations/{$membership->id}/respond", ['decision' => 'accept'])
            ->assertForbidden();
    }

    public function test_it_requires_a_token(): void
    {
        $this->getJson('/api/v1/cooperative-driver-invitations')->assertUnauthorized();
    }
}
