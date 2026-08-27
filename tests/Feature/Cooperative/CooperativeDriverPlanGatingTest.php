<?php

namespace Tests\Feature\Cooperative;

use App\Models\Cooperative;
use App\Models\CooperativeDriverMembership;
use App\Models\DriverProfile;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\RideDispatchCandidates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pedido explícito del usuario: "cuando una cooperativa tenga que buscar a
 * un conductor, y pueda permanecer en su cooperativa activo, tiene que
 * tener el plan mayor al gratis, y tiene que estar vigente. por lo
 * contrario aparecera bloqueado" — ver PlanLimits::hasActivePaidPlan(),
 * RideDispatchCandidates::forCooperative() y CooperativeDriverController::index().
 */
class CooperativeDriverPlanGatingTest extends TestCase
{
    use RefreshDatabase;

    private function approvedCooperative(): Cooperative
    {
        $cooperativeUser = User::factory()->create();
        $cooperative = Cooperative::query()->create(['user_id' => $cooperativeUser->id, 'name' => 'Coop Central']);
        $cooperative->forceFill(['status' => 'approved'])->save();

        return $cooperative;
    }

    private function affiliatedDriver(Cooperative $cooperative, ?array $subscriptionOverrides = null): User
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create([
            'driver_type' => 'public_transport',
            'is_available' => true,
            'current_lat' => -0.1807,
            'current_lng' => -78.4678,
        ]);
        CooperativeDriverMembership::query()->create([
            'cooperative_id' => $cooperative->id,
            'driver_user_id' => $driver->id,
            'invited_by_user_id' => $cooperative->user_id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);

        if ($subscriptionOverrides !== null) {
            $plan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'plus')->firstOrFail();
            Subscription::factory()->for($driver)->create(array_merge(['subscription_plan_id' => $plan->id], $subscriptionOverrides));
        }

        return $driver;
    }

    public function test_a_driver_on_the_free_plan_is_excluded_from_automatic_cooperative_dispatch(): void
    {
        $cooperative = $this->approvedCooperative();
        $this->affiliatedDriver($cooperative);

        $candidates = RideDispatchCandidates::forCooperative($cooperative, -0.1807, -78.4678);

        $this->assertEmpty($candidates);
    }

    public function test_a_driver_with_an_active_paid_plan_is_offered_the_ride(): void
    {
        $cooperative = $this->approvedCooperative();
        $driver = $this->affiliatedDriver($cooperative, ['status' => 'active']);

        $candidates = RideDispatchCandidates::forCooperative($cooperative, -0.1807, -78.4678);

        $this->assertSame([$driver->id], $candidates);
    }

    public function test_a_driver_with_an_expired_paid_plan_is_excluded_too(): void
    {
        $cooperative = $this->approvedCooperative();
        $this->affiliatedDriver($cooperative, ['status' => 'expired']);

        $candidates = RideDispatchCandidates::forCooperative($cooperative, -0.1807, -78.4678);

        $this->assertEmpty($candidates);
    }

    public function test_the_cooperative_roster_flags_an_accepted_driver_on_the_free_plan_as_blocked(): void
    {
        $cooperativeUser = User::factory()->create();
        $cooperative = Cooperative::query()->create(['user_id' => $cooperativeUser->id, 'name' => 'Coop Central']);
        $cooperative->forceFill(['status' => 'approved'])->save();
        $driver = $this->affiliatedDriver($cooperative);

        $response = $this->actingAs($cooperativeUser)->get(route('cooperative.drivers.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('memberships.0.driver_user_id', $driver->id)
            ->where('memberships.0.is_plan_blocked', true)
        );
    }

    public function test_the_cooperative_roster_does_not_flag_a_driver_with_an_active_plan(): void
    {
        $cooperativeUser = User::factory()->create();
        $cooperative = Cooperative::query()->create(['user_id' => $cooperativeUser->id, 'name' => 'Coop Central']);
        $cooperative->forceFill(['status' => 'approved'])->save();
        $this->affiliatedDriver($cooperative, ['status' => 'active']);

        $response = $this->actingAs($cooperativeUser)->get(route('cooperative.drivers.index'));

        $response->assertInertia(fn ($page) => $page->where('memberships.0.is_plan_blocked', false));
    }

    /**
     * Un vínculo apenas invitado (todavía no aceptado) no está "activo" por
     * ningún otro motivo — no hace falta marcarlo también como bloqueado
     * por plan, sería redundante.
     */
    public function test_a_pending_membership_is_never_flagged_as_plan_blocked(): void
    {
        $cooperativeUser = User::factory()->create();
        $cooperative = Cooperative::query()->create(['user_id' => $cooperativeUser->id, 'name' => 'Coop Central']);
        $cooperative->forceFill(['status' => 'approved'])->save();

        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        CooperativeDriverMembership::query()->create([
            'cooperative_id' => $cooperative->id,
            'driver_user_id' => $driver->id,
            'invited_by_user_id' => $cooperativeUser->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($cooperativeUser)->get(route('cooperative.drivers.index'));

        $response->assertInertia(fn ($page) => $page->where('memberships.0.is_plan_blocked', false));
    }
}
