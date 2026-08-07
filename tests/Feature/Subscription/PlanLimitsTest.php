<?php

namespace Tests\Feature\Subscription;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\PlanLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 5 (secciones 7.2, 7.3 y 9.6): PlanLimits es la única fuente de verdad
 * para los cupos y funciones de cada plan. Cubre la cascada de resolución
 * (suscripción vigente -> plan Gratis, siempre presente porque lo siembra la
 * propia migración de subscription_plans) y los overrides "custom_*" del
 * plan Institucional.
 */
class PlanLimitsTest extends TestCase
{
    use RefreshDatabase;

    private PlanLimits $planLimits;

    protected function setUp(): void
    {
        parent::setUp();

        $this->planLimits = app(PlanLimits::class);
    }

    public function test_driver_without_subscription_falls_back_to_the_free_plan_in_the_database(): void
    {
        $driver = User::factory()->create();

        $limits = $this->planLimits->forDriver($driver);

        $this->assertSame('gratis', $limits['plan_code']);
        $this->assertSame(3, $limits['max_clients']);
        $this->assertFalse($limits['public_visibility']);
        $this->assertFalse($limits['priority_listing']);
        $this->assertFalse($limits['verified_badge']);
        // A diferencia de las demás (premium, arrancan apagadas), Expresos ya
        // era una función abierta antes de este flag — el plan Gratis
        // sembrado por la migración arranca CON el módulo habilitado.
        $this->assertTrue($limits['express_enabled']);
    }

    public function test_client_without_subscription_falls_back_to_the_free_plan_in_the_database(): void
    {
        $client = User::factory()->create();

        $limits = $this->planLimits->forClient($client);

        $this->assertSame('gratis', $limits['plan_code']);
        $this->assertSame(1, $limits['max_fleets']);
        $this->assertSame(20, $limits['max_drivers_per_fleet']);
    }

    public function test_driver_with_an_active_plus_subscription_gets_its_limits_and_features(): void
    {
        $driver = User::factory()->create();
        $plusPlan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'plus')->firstOrFail();

        Subscription::factory()->for($driver)->create([
            'subscription_plan_id' => $plusPlan->id,
            'status' => 'active',
        ]);

        $limits = $this->planLimits->forDriver($driver);

        $this->assertSame('plus', $limits['plan_code']);
        $this->assertSame(45, $limits['max_clients']);
        $this->assertTrue($limits['public_visibility']);
        $this->assertFalse($limits['priority_listing']);
    }

    /**
     * Pedido explícito del usuario: poder sacarle Expresos a un plan puntual
     * desde /admin/planes.
     */
    public function test_a_plan_with_express_disabled_reports_it_as_such(): void
    {
        $driver = User::factory()->create();
        $plan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'basico')->firstOrFail();
        $plan->update(['express_enabled' => false]);

        Subscription::factory()->for($driver)->create([
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
        ]);

        $limits = $this->planLimits->forDriver($driver);

        $this->assertFalse($limits['express_enabled']);
    }

    public function test_institutional_custom_override_takes_precedence_over_the_plan_default(): void
    {
        $driver = User::factory()->create();
        $institutionalPlan = SubscriptionPlan::query()
            ->where('owner_type', 'driver')
            ->where('code', 'institucional')
            ->firstOrFail();

        $this->assertNull($institutionalPlan->max_clients);

        Subscription::factory()->for($driver)->create([
            'subscription_plan_id' => $institutionalPlan->id,
            'status' => 'active',
            'custom_max_clients' => 200,
        ]);

        $limits = $this->planLimits->forDriver($driver);

        $this->assertSame(200, $limits['max_clients']);
    }

    public function test_an_expired_subscription_does_not_count_and_falls_back_to_free(): void
    {
        $client = User::factory()->create();
        $multiFlotaPlan = SubscriptionPlan::query()
            ->where('owner_type', 'client')
            ->where('code', 'multiflota')
            ->firstOrFail();

        Subscription::factory()->for($client)->create([
            'subscription_plan_id' => $multiFlotaPlan->id,
            'status' => 'expired',
        ]);

        $limits = $this->planLimits->forClient($client);

        $this->assertSame('gratis', $limits['plan_code']);
        $this->assertSame(1, $limits['max_fleets']);
    }

    public function test_a_subscription_in_grace_period_still_counts_as_usable(): void
    {
        $client = User::factory()->create();
        $multiFlotaPlan = SubscriptionPlan::query()
            ->where('owner_type', 'client')
            ->where('code', 'multiflota')
            ->firstOrFail();

        Subscription::factory()->for($client)->create([
            'subscription_plan_id' => $multiFlotaPlan->id,
            'status' => 'grace',
        ]);

        $limits = $this->planLimits->forClient($client);

        $this->assertSame('multiflota', $limits['plan_code']);
        $this->assertSame(5, $limits['max_fleets']);
    }
}
