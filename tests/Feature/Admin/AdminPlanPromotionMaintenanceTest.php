<?php

namespace Tests\Feature\Admin;

use App\Models\PlanPromotion;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pantalla de mantenimiento de promociones (pedido explícito del usuario:
 * "regalar o promocionar los planes por un tiempo determinado... y
 * modificarlo desde ahí") — /admin/promociones.
 */
class AdminPlanPromotionMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_regular_user_cannot_access_the_promotions_screen(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.plan-promotions.index'))->assertForbidden();
    }

    public function test_an_admin_can_create_a_promotion(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $plan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'plus')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.plan-promotions.store'), [
            'subscription_plan_id' => $plan->id,
            'label' => '1 mes gratis',
            'promo_price' => 0,
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addDays(30)->toDateString(),
        ])->assertRedirect();

        $this->assertDatabaseHas('plan_promotions', [
            'subscription_plan_id' => $plan->id,
            'label' => '1 mes gratis',
            'promo_price' => 0,
        ]);
    }

    /**
     * Pedido explícito del usuario ("pagá tanto y ahorrá tanto"): un precio
     * promocional que no sea menor al de lista no es una promoción.
     */
    public function test_the_promo_price_must_be_lower_than_the_plans_list_price(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $plan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'plus')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.plan-promotions.store'), [
            'subscription_plan_id' => $plan->id,
            'label' => 'No es promo',
            'promo_price' => $plan->monthly_price,
        ])->assertSessionHasErrors('promo_price');

        $this->assertDatabaseCount('plan_promotions', 0);
    }

    public function test_an_admin_can_update_a_promotion(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $plan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'plus')->firstOrFail();
        $promotion = PlanPromotion::query()->create([
            'subscription_plan_id' => $plan->id,
            'label' => 'Original',
            'promo_price' => 5,
        ]);

        $this->actingAs($admin)->patch(route('admin.plan-promotions.update', $promotion), [
            'subscription_plan_id' => $plan->id,
            'label' => 'Actualizada',
            'promo_price' => 3,
            'is_active' => false,
        ])->assertRedirect();

        $this->assertDatabaseHas('plan_promotions', [
            'id' => $promotion->id,
            'label' => 'Actualizada',
            'promo_price' => 3,
            'is_active' => false,
        ]);
    }

    public function test_an_admin_can_delete_a_promotion(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $plan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'plus')->firstOrFail();
        $promotion = PlanPromotion::query()->create([
            'subscription_plan_id' => $plan->id,
            'label' => 'A borrar',
            'promo_price' => 0,
        ]);

        $this->actingAs($admin)->delete(route('admin.plan-promotions.destroy', $promotion))->assertRedirect();

        $this->assertDatabaseMissing('plan_promotions', ['id' => $promotion->id]);
    }
}
