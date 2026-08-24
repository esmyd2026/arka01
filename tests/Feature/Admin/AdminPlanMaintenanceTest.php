<?php

namespace Tests\Feature\Admin;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pantalla de mantenimiento del catálogo de planes (secciones 7.2 y 7.3):
 * nada de esto puede quedar quemado en código — un admin crea, edita,
 * desactiva o elimina un plan por completo desde /admin/planes.
 */
class AdminPlanMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_regular_user_cannot_access_the_plans_maintenance_screen(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.plans.index'))->assertForbidden();
    }

    public function test_an_admin_can_create_a_new_plan(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.plans.store'), [
            'owner_type' => 'driver',
            'code' => 'premium',
            'name' => 'Premium',
            'monthly_price' => 30,
            'max_clients' => 100,
            'public_visibility' => true,
            'priority_listing' => true,
            'verified_badge' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('subscription_plans', [
            'owner_type' => 'driver',
            'code' => 'premium',
            'name' => 'Premium',
            'monthly_price' => 30,
        ]);
    }

    /**
     * Pedido explícito del usuario: sin especificarlo, un plan nuevo arranca
     * CON Expresos habilitado — a diferencia de las funciones premium
     * (directorio, prioridad, insignia, VAN), que arrancan apagadas.
     */
    public function test_a_new_plan_defaults_to_express_enabled_when_not_specified(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.plans.store'), [
            'owner_type' => 'driver',
            'code' => 'premium',
            'name' => 'Premium',
            'monthly_price' => 30,
        ])->assertRedirect();

        $this->assertDatabaseHas('subscription_plans', ['code' => 'premium', 'express_enabled' => true]);
    }

    public function test_an_admin_can_disable_express_routes_for_a_plan(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $plan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'basico')->firstOrFail();

        $this->actingAs($admin)->patch(route('admin.plans.update', $plan), [
            'owner_type' => 'driver',
            'code' => 'basico',
            'name' => 'Básico',
            'monthly_price' => $plan->monthly_price,
            'max_clients' => $plan->max_clients,
            'express_enabled' => false,
        ])->assertRedirect();

        $this->assertDatabaseHas('subscription_plans', ['id' => $plan->id, 'express_enabled' => false]);
    }

    public function test_an_admin_can_edit_an_existing_plans_price_and_limits(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $plan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'basico')->firstOrFail();

        $this->actingAs($admin)->patch(route('admin.plans.update', $plan), [
            'owner_type' => 'driver',
            'code' => 'basico',
            'name' => 'Básico',
            'monthly_price' => 12.5,
            'max_clients' => 30,
            'public_visibility' => false,
            'priority_listing' => false,
            'verified_badge' => false,
        ])->assertRedirect();

        $this->assertDatabaseHas('subscription_plans', [
            'id' => $plan->id,
            'monthly_price' => 12.5,
            'max_clients' => 30,
        ]);
    }

    /**
     * Proyección de ganancia (pedido explícito del usuario: "indiquemos en
     * cada plan las carreras estimadas y un estimado a ganar mensualmente")
     * — referencia editable desde acá, sin tocar código.
     */
    public function test_an_admin_can_set_the_estimated_monthly_rides_for_a_plan(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $plan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'basico')->firstOrFail();

        $this->actingAs($admin)->patch(route('admin.plans.update', $plan), [
            'owner_type' => 'driver',
            'code' => 'basico',
            'name' => 'Básico',
            'monthly_price' => $plan->monthly_price,
            'max_clients' => $plan->max_clients,
            'estimated_monthly_rides' => 175,
        ])->assertRedirect();

        $this->assertDatabaseHas('subscription_plans', ['id' => $plan->id, 'estimated_monthly_rides' => 175]);
    }

    public function test_the_free_plan_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $freePlan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'gratis')->firstOrFail();

        $this->actingAs($admin)
            ->delete(route('admin.plans.destroy', $freePlan))
            ->assertSessionHasErrors('plan');

        $this->assertDatabaseHas('subscription_plans', ['id' => $freePlan->id]);
    }

    /**
     * Ahora que la cooperativa tiene un `gratis` real (pedido explícito del
     * usuario: agregar el descuento cruzado + un plan gratuito de 5
     * unidades), es ESE el que no se puede borrar — `basico` ya no es un
     * caso especial (antes lo era, porque hacía de plan base sin haber un
     * `gratis` de verdad para ese lado).
     */
    public function test_the_cooperative_free_plan_cannot_be_deleted_but_basico_now_can(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $freePlan = SubscriptionPlan::query()->where('owner_type', 'cooperative')->where('code', 'gratis')->firstOrFail();
        $basicoPlan = SubscriptionPlan::query()->where('owner_type', 'cooperative')->where('code', 'basico')->firstOrFail();

        $this->actingAs($admin)
            ->delete(route('admin.plans.destroy', $freePlan))
            ->assertSessionHasErrors('plan');
        $this->assertDatabaseHas('subscription_plans', ['id' => $freePlan->id]);

        $this->actingAs($admin)->delete(route('admin.plans.destroy', $basicoPlan))->assertRedirect();
        $this->assertDatabaseMissing('subscription_plans', ['id' => $basicoPlan->id]);
    }

    public function test_a_plan_with_subscribers_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $plan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'plus')->firstOrFail();

        $user = User::factory()->create();
        Subscription::factory()->for($user)->create(['subscription_plan_id' => $plan->id]);

        $this->actingAs($admin)
            ->delete(route('admin.plans.destroy', $plan))
            ->assertSessionHasErrors('plan');

        $this->assertDatabaseHas('subscription_plans', ['id' => $plan->id]);
    }

    public function test_a_plan_never_used_can_be_deleted(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $plan = SubscriptionPlan::query()->create([
            'owner_type' => 'driver',
            'code' => 'temporal',
            'name' => 'Temporal',
            'monthly_price' => 5,
        ]);

        $this->actingAs($admin)->delete(route('admin.plans.destroy', $plan))->assertRedirect();

        $this->assertDatabaseMissing('subscription_plans', ['id' => $plan->id]);
    }

    public function test_deactivating_a_plan_hides_it_from_a_users_own_plan_catalog_unless_its_their_current_one(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $plan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'pro')->firstOrFail();

        $this->actingAs($admin)->patch(route('admin.plans.update', $plan), [
            'owner_type' => 'driver',
            'code' => 'pro',
            'name' => 'Pro',
            'monthly_price' => $plan->monthly_price,
            'max_clients' => $plan->max_clients,
            'public_visibility' => true,
            'priority_listing' => true,
            'verified_badge' => true,
            'is_active' => false,
        ])->assertRedirect();

        $this->assertDatabaseHas('subscription_plans', ['id' => $plan->id, 'is_active' => false]);

        // Un conductor sin ese plan ya no lo ve en su catálogo de "Mi plan".
        $driverWithoutPro = User::factory()->create();
        $response = $this->actingAs($driverWithoutPro)->get(route('driver.plan.edit'));
        $response->assertInertia(fn ($page) => $page
            ->where('plans', fn ($plans) => ! collect($plans)->contains('code', 'pro'))
        );

        // Pero alguien que ya lo tenía contratado lo sigue viendo.
        $driverWithPro = User::factory()->create();
        Subscription::factory()->for($driverWithPro)->create(['subscription_plan_id' => $plan->id, 'status' => 'active']);

        $response = $this->actingAs($driverWithPro)->get(route('driver.plan.edit'));
        $response->assertInertia(fn ($page) => $page
            ->where('plans', fn ($plans) => collect($plans)->contains('code', 'pro'))
        );
    }
}
