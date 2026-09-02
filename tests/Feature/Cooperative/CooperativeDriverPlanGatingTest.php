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
 * Corrección explícita del usuario sobre una regla anterior ("cuando una
 * cooperativa tenga que buscar a un conductor... tiene que tener el plan
 * mayor al gratis... por lo contrario aparecerá bloqueado"): la cooperativa
 * es quien paga para que su conductor opere dentro de ella — el conductor no
 * paga dos veces. Su plan personal (incluido Gratis) sigue limitando
 * únicamente su cartera privada propia (ver
 * DriverAccessResolverTest/CooperativeDriverPrivateClientCapacityTest),
 * nunca su elegibilidad para el despacho de la cooperativa ni el roster que
 * ve el operador — ver RideDispatchCandidates::forCooperative() y
 * CooperativeDriverController::index().
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

    public function test_a_driver_on_the_free_plan_is_still_offered_automatic_cooperative_dispatch(): void
    {
        $cooperative = $this->approvedCooperative();
        $driver = $this->affiliatedDriver($cooperative);

        $candidates = RideDispatchCandidates::forCooperative($cooperative, -0.1807, -78.4678);

        $this->assertSame([$driver->id], $candidates);
    }

    public function test_a_driver_with_an_expired_paid_plan_is_still_offered_automatic_cooperative_dispatch(): void
    {
        $cooperative = $this->approvedCooperative();
        $driver = $this->affiliatedDriver($cooperative, ['status' => 'expired']);

        $candidates = RideDispatchCandidates::forCooperative($cooperative, -0.1807, -78.4678);

        $this->assertSame([$driver->id], $candidates);
    }

    public function test_a_driver_with_an_active_paid_plan_is_offered_the_ride(): void
    {
        $cooperative = $this->approvedCooperative();
        $driver = $this->affiliatedDriver($cooperative, ['status' => 'active']);

        $candidates = RideDispatchCandidates::forCooperative($cooperative, -0.1807, -78.4678);

        $this->assertSame([$driver->id], $candidates);
    }

    public function test_the_cooperative_roster_no_longer_exposes_a_plan_blocked_flag(): void
    {
        $cooperativeUser = User::factory()->create();
        $cooperative = Cooperative::query()->create(['user_id' => $cooperativeUser->id, 'name' => 'Coop Central']);
        $cooperative->forceFill(['status' => 'approved'])->save();
        $driver = $this->affiliatedDriver($cooperative);

        $response = $this->actingAs($cooperativeUser)->get(route('cooperative.drivers.index'));

        $response->assertInertia(function ($page) use ($driver) {
            $memberships = collect($page->toArray()['props']['memberships']);
            $this->assertSame($driver->id, $memberships->first()['driver_user_id']);
            $this->assertArrayNotHasKey('is_plan_blocked', $memberships->first());
        });
    }
}
