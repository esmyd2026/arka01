<?php

namespace Tests\Feature\Plan;

use App\Models\Cooperative;
use App\Models\CooperativeDriverMembership;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Mi plan de cooperativa" (pedido explícito del usuario: "dame los
 * beneficios de cada plan y muéstralo en los planes de cada cooperativa") —
 * no existía ninguna pantalla de catálogo/cambio de plan para este rol,
 * mismo patrón que ya tenían conductor y cliente (MyPlanController).
 */
class CooperativePlanTest extends TestCase
{
    use RefreshDatabase;

    private function createCooperative(): User
    {
        $user = User::factory()->create();
        Cooperative::query()->create(['user_id' => $user->id, 'name' => 'Coop de prueba']);

        return $user;
    }

    public function test_a_cooperative_without_subscription_falls_back_to_the_free_plan_and_sees_the_catalog(): void
    {
        $user = $this->createCooperative();

        $response = $this->actingAs($user)->get(route('cooperative.plan.edit'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('currentPlan.plan_code', 'gratis')
            ->where('currentPlan.max_units', 5)
            ->where('plans', fn ($plans) => collect($plans)->contains('code', 'gratis')
                && collect($plans)->contains('code', 'basico')
                && collect($plans)->contains('code', 'profesional')
                // El plan "empresarial" fue discontinuado (pedido explícito
                // del usuario: sacar el tramo "sin límite") — no debe
                // ofrecerse a quien nunca lo tuvo.
                && ! collect($plans)->contains('code', 'empresarial')
            )
        );
    }

    /**
     * Sección 19: las suscripciones son una progresión, nunca un retroceso —
     * mismo criterio ya probado para conductor/cliente
     * (SubscriptionRequestFlowTest::test_the_driver_plan_catalog_never_includes_lower_tier_plans).
     */
    public function test_the_cooperative_plan_catalog_never_includes_lower_tier_plans(): void
    {
        $user = $this->createCooperative();
        $profesionalPlan = SubscriptionPlan::query()->where('owner_type', 'cooperative')->where('code', 'profesional')->firstOrFail();

        Subscription::query()->create([
            'user_id' => $user->id,
            'subscription_plan_id' => $profesionalPlan->id,
            'status' => 'active',
            'started_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('cooperative.plan.edit'));

        $response->assertInertia(fn ($page) => $page
            ->where('plans', fn ($plans) => ! collect($plans)->contains('code', 'gratis')
                && ! collect($plans)->contains('code', 'basico')
                && collect($plans)->contains('code', 'profesional')
            )
        );
    }

    public function test_a_cooperative_can_select_a_paid_plan_and_it_creates_an_awaiting_proof_request(): void
    {
        $user = $this->createCooperative();
        $basicoPlan = SubscriptionPlan::query()->where('owner_type', 'cooperative')->where('code', 'basico')->firstOrFail();

        $this->actingAs($user)
            ->post(route('subscription-requests.store'), ['subscription_plan_id' => $basicoPlan->id])
            ->assertRedirect();

        $this->assertDatabaseHas('subscription_requests', [
            'user_id' => $user->id,
            'subscription_plan_id' => $basicoPlan->id,
            'status' => 'awaiting_proof',
        ]);
    }

    public function test_used_units_counts_active_driver_memberships_only(): void
    {
        $user = $this->createCooperative();
        $cooperative = $user->cooperative;

        $activeDriver = User::factory()->create();
        CooperativeDriverMembership::query()->create([
            'cooperative_id' => $cooperative->id,
            'driver_user_id' => $activeDriver->id,
            'invited_by_user_id' => $user->id,
            'status' => 'accepted',
        ]);

        $endedDriver = User::factory()->create();
        CooperativeDriverMembership::query()->create([
            'cooperative_id' => $cooperative->id,
            'driver_user_id' => $endedDriver->id,
            'invited_by_user_id' => $user->id,
            'status' => 'accepted',
            'ended_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($user)->get(route('cooperative.plan.edit'));

        $response->assertInertia(fn ($page) => $page->where('usedUnits', 1));
    }
}
