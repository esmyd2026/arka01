<?php

namespace Tests\Feature\Api\V1;

use App\Models\Cooperative;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\Subscription;
use App\Models\SubscriptionChange;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Mi plan" (solo lectura) desde la app móvil (roadmap Hito 2, pasada "full
 * backend"). Reusa App\Services\PlanLimits/PlanUsageCalculator, las mismas
 * piezas que MyPlanController (web) — estos casos se enfocan en el contrato
 * JSON y en que cada rol reciba su propia forma.
 */
class MobilePlanTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_a_client_sees_their_plan_and_fleet_usage(): void
    {
        $client = User::factory()->create();
        Fleet::factory()->for($client, 'owner')->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->getJson('/api/v1/my-plan');

        $response->assertOk()
            ->assertJsonPath('owner_type', 'client')
            ->assertJsonPath('current_plan.plan_code', 'gratis')
            ->assertJsonPath('used_fleets', 1);
    }

    public function test_a_driver_sees_their_plan_and_active_client_count(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->getJson('/api/v1/my-plan');

        $response->assertOk()
            ->assertJsonPath('owner_type', 'driver')
            ->assertJsonPath('current_plan.plan_code', 'gratis')
            ->assertJsonPath('used_clients', 1);
    }

    public function test_a_cooperative_sees_their_plan_and_unit_usage(): void
    {
        $owner = User::factory()->create();
        Cooperative::query()->create(['user_id' => $owner->id]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson('/api/v1/my-plan');

        $response->assertOk()
            ->assertJsonPath('owner_type', 'cooperative')
            ->assertJsonPath('current_plan.plan_code', 'gratis')
            ->assertJsonPath('used_units', 0);
    }

    public function test_the_change_history_reflects_a_past_plan_change(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $oldPlan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'gratis')->firstOrFail();
        $newPlan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'basico')->firstOrFail();

        SubscriptionChange::query()->create([
            'user_id' => $driver->id,
            'old_subscription_plan_id' => $oldPlan->id,
            'new_subscription_plan_id' => $newPlan->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->getJson('/api/v1/my-plan');

        $response->assertOk()
            ->assertJsonCount(1, 'changes')
            ->assertJsonPath('changes.0.old_plan.code', 'gratis')
            ->assertJsonPath('changes.0.new_plan.code', 'basico');
    }

    public function test_it_reflects_an_active_paid_subscription(): void
    {
        $client = User::factory()->create();
        $plan = SubscriptionPlan::query()->where('owner_type', 'client')->where('code', 'plus')->firstOrFail();
        Subscription::factory()->for($client)->create(['subscription_plan_id' => $plan->id, 'status' => 'active']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->getJson('/api/v1/my-plan')
            ->assertOk()
            ->assertJsonPath('current_plan.plan_code', 'plus');
    }

    public function test_it_requires_a_token(): void
    {
        $this->getJson('/api/v1/my-plan')->assertUnauthorized();
    }
}
