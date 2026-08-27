<?php

namespace Tests\Feature\Admin;

use App\Models\PlanCoupon;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pantalla de mantenimiento de cupones de descuento (pedido explícito del
 * usuario: "generar cupones de descuentos... para clientes y para
 * conductores como para cooperativa") — /admin/cupones-de-planes.
 */
class AdminPlanCouponMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_regular_user_cannot_access_the_coupons_screen(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.plan-coupons.index'))->assertForbidden();
    }

    public function test_an_admin_can_create_a_coupon(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.plan-coupons.store'), [
            'code' => 'bienvenida50',
            'owner_type' => 'client',
            'discount_percent' => 50,
            'max_redemptions' => 100,
            'label' => 'Campaña de lanzamiento',
        ])->assertRedirect();

        // El código se normaliza siempre a mayúsculas.
        $this->assertDatabaseHas('plan_coupons', [
            'code' => 'BIENVENIDA50',
            'owner_type' => 'client',
            'discount_percent' => 50,
            'max_redemptions' => 100,
            'created_by_admin_id' => $admin->id,
        ]);
    }

    public function test_the_code_must_be_unique(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        PlanCoupon::query()->create(['code' => 'YAEXISTE', 'owner_type' => 'client', 'discount_percent' => 50]);

        $this->actingAs($admin)->post(route('admin.plan-coupons.store'), [
            'code' => 'yaexiste',
            'owner_type' => 'driver',
            'discount_percent' => 20,
        ])->assertSessionHasErrors('code');
    }

    public function test_the_discount_percent_cannot_exceed_100(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.plan-coupons.store'), [
            'code' => 'INVALIDO',
            'owner_type' => 'client',
            'discount_percent' => 150,
        ])->assertSessionHasErrors('discount_percent');

        $this->assertDatabaseCount('plan_coupons', 0);
    }

    /**
     * Pedido explícito del usuario: "colocarle un usuario para que cuando
     * se registren tenga a ese usuario que le dio el cupon como referido".
     */
    public function test_an_admin_can_create_a_coupon_with_a_referrer(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $referrer = User::factory()->create();

        $this->actingAs($admin)->post(route('admin.plan-coupons.store'), [
            'code' => 'CONREFERIDO',
            'owner_type' => 'client',
            'discount_percent' => 50,
            'referrer_user_id' => $referrer->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('plan_coupons', [
            'code' => 'CONREFERIDO',
            'referrer_user_id' => $referrer->id,
        ]);
    }

    public function test_the_referrer_search_finds_a_user_by_partial_name(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $target = User::factory()->create(['name' => 'Gabriela Parrales']);

        $response = $this->actingAs($admin)->getJson(route('admin.plan-coupons.search-referrer', ['q' => 'Gabriela']));

        $response->assertOk();
        $response->assertJsonFragment(['id' => $target->id]);
    }

    public function test_a_regular_user_cannot_search_referrers(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->getJson(route('admin.plan-coupons.search-referrer', ['q' => 'algo']))->assertForbidden();
    }

    public function test_an_admin_can_update_a_coupon(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $coupon = PlanCoupon::query()->create(['code' => 'ORIGINAL', 'owner_type' => 'client', 'discount_percent' => 20]);

        $this->actingAs($admin)->patch(route('admin.plan-coupons.update', $coupon), [
            'code' => 'ACTUALIZADO',
            'owner_type' => 'driver',
            'discount_percent' => 40,
            'is_active' => false,
        ])->assertRedirect();

        $this->assertDatabaseHas('plan_coupons', [
            'id' => $coupon->id,
            'code' => 'ACTUALIZADO',
            'owner_type' => 'driver',
            'discount_percent' => 40,
            'is_active' => false,
        ]);
    }

    public function test_an_admin_can_toggle_a_coupon_active(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $coupon = PlanCoupon::query()->create(['code' => 'TOGGLE', 'owner_type' => 'client', 'discount_percent' => 50, 'is_active' => true]);

        $this->actingAs($admin)->post(route('admin.plan-coupons.toggle', $coupon))->assertRedirect();

        $this->assertFalse($coupon->fresh()->is_active);

        $this->actingAs($admin)->post(route('admin.plan-coupons.toggle', $coupon))->assertRedirect();

        $this->assertTrue($coupon->fresh()->is_active);
    }

    public function test_an_admin_can_delete_a_coupon(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $coupon = PlanCoupon::query()->create(['code' => 'ABORRAR', 'owner_type' => 'client', 'discount_percent' => 50]);

        $this->actingAs($admin)->delete(route('admin.plan-coupons.destroy', $coupon))->assertRedirect();

        $this->assertDatabaseMissing('plan_coupons', ['id' => $coupon->id]);
    }

    /**
     * Pedido explícito del usuario ("si el cupon cubre el 100 o 50"): el
     * listado tiene que mostrar cuántas veces se usó de verdad, no un
     * contador que se pueda desincronizar.
     */
    public function test_the_index_reports_real_redemption_counts(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $plan = SubscriptionPlan::query()->where('owner_type', 'client')->where('code', 'multiflota')->firstOrFail();
        $coupon = PlanCoupon::query()->create(['code' => 'CONTADO', 'owner_type' => 'client', 'discount_percent' => 50]);

        SubscriptionRequest::query()->create([
            'user_id' => User::factory()->create()->id,
            'subscription_plan_id' => $plan->id,
            'plan_coupon_id' => $coupon->id,
            'status' => 'approved',
        ]);
        // Un pedido rechazado NO cuenta como uso real.
        SubscriptionRequest::query()->create([
            'user_id' => User::factory()->create()->id,
            'subscription_plan_id' => $plan->id,
            'plan_coupon_id' => $coupon->id,
            'status' => 'rejected',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.plan-coupons.index'));

        $response->assertInertia(fn ($page) => $page->where('coupons.0.redemptions_count', 1));
    }
}
