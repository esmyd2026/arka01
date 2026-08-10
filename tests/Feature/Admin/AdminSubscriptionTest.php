<?php

namespace Tests\Feature\Admin;

use App\Models\DriverProfile;
use App\Models\Subscription;
use App\Models\SubscriptionChange;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Notifications\PlanActivatedPushNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Panel admin de suscripciones (secciones 7.5 y 9.6): activación manual tras
 * confirmar una transferencia, con auditoría completa de cada cambio. Solo
 * accesible para usuarios con is_admin. El catálogo de planes ya viene
 * sembrado por la propia migración (ver create_subscription_plans_table), no
 * hace falta un seeder aparte para estos tests.
 */
class AdminSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_regular_user_cannot_access_the_admin_panel(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.subscriptions.index'))->assertForbidden();
    }

    public function test_an_admin_can_activate_a_plan_for_a_user_and_it_gets_audited(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        // Cada cuenta es cliente O conductor, nunca las dos (sección 3.1) —
        // un plan del lado conductor solo se le puede activar a alguien que
        // de verdad activó ese perfil.
        DriverProfile::factory()->for($user)->create();
        $plusPlan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'plus')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.subscriptions.store'), [
            'user_id' => $user->id,
            'subscription_plan_id' => $plusPlan->id,
            'note' => 'Transferencia #123',
        ])->assertRedirect();

        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $user->id,
            'subscription_plan_id' => $plusPlan->id,
            'status' => 'active',
            'activated_by' => $admin->id,
        ]);

        $this->assertDatabaseHas('subscription_changes', [
            'user_id' => $user->id,
            'old_subscription_plan_id' => null,
            'new_subscription_plan_id' => $plusPlan->id,
            'changed_by' => $admin->id,
        ]);
    }

    /**
     * Reporte del usuario: "activé un plan a un cliente y no le llegó la
     * notificación" — no existía ningún aviso, en ninguna de las formas de
     * activar un plan (todas pasan por SubscriptionActivator::activate()).
     */
    public function test_activating_a_plan_notifies_the_user(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        DriverProfile::factory()->for($user)->create();
        $plusPlan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'plus')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.subscriptions.store'), [
            'user_id' => $user->id,
            'subscription_plan_id' => $plusPlan->id,
        ])->assertRedirect();

        Notification::assertSentTo($user, PlanActivatedPushNotification::class);
    }

    public function test_activating_a_new_plan_cancels_the_users_previous_active_plan_of_the_same_side(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        DriverProfile::factory()->for($user)->create();
        $basicoPlan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'basico')->firstOrFail();
        $proPlan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'pro')->firstOrFail();

        $previousSubscription = Subscription::factory()->for($user)->create([
            'subscription_plan_id' => $basicoPlan->id,
            'status' => 'active',
        ]);

        $this->actingAs($admin)->post(route('admin.subscriptions.store'), [
            'user_id' => $user->id,
            'subscription_plan_id' => $proPlan->id,
        ])->assertRedirect();

        $this->assertSame('cancelled', $previousSubscription->fresh()->status);

        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $user->id,
            'subscription_plan_id' => $proPlan->id,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('subscription_changes', [
            'user_id' => $user->id,
            'old_subscription_plan_id' => $basicoPlan->id,
            'new_subscription_plan_id' => $proPlan->id,
        ]);
    }

    public function test_an_admin_can_expire_a_subscription_and_it_gets_audited(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        $plusPlan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'plus')->firstOrFail();

        $subscription = Subscription::factory()->for($user)->create([
            'subscription_plan_id' => $plusPlan->id,
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.subscriptions.expire', $subscription))
            ->assertRedirect();

        $this->assertSame('expired', $subscription->fresh()->status);
        $this->assertTrue(
            SubscriptionChange::where('user_id', $user->id)
                ->where('old_subscription_plan_id', $plusPlan->id)
                ->exists()
        );
    }

    /**
     * Pedido explícito del usuario: la lista de suscriptores pasó de tarjetas
     * apiladas a una tabla con filtro por rol/estado de plan y orden por
     * nombre o vencimiento — cubre que el filtrado y el orden lleguen
     * correctamente hasta la vista, no solo que la página cargue.
     */
    public function test_the_subscriptions_list_can_be_filtered_by_role_and_plan_status(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $client = User::factory()->create(['role' => 'cliente']);
        $driverWithPlan = User::factory()->create(['role' => 'conductor']);
        DriverProfile::factory()->for($driverWithPlan)->create();
        $plusPlan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'plus')->firstOrFail();
        Subscription::factory()->for($driverWithPlan)->create([
            'subscription_plan_id' => $plusPlan->id,
            'status' => 'active',
            'expires_at' => now()->addDays(10),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.subscriptions.index', ['role' => 'conductor']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Subscriptions')
            ->has('users.data', 1)
            ->where('users.data.0.id', $driverWithPlan->id)
        );

        $response = $this->actingAs($admin)->get(route('admin.subscriptions.index', ['plan_status' => 'con_plan']));
        $response->assertInertia(fn ($page) => $page
            ->has('users.data', 1)
            ->where('users.data.0.id', $driverWithPlan->id)
        );

        $response = $this->actingAs($admin)->get(route('admin.subscriptions.index', ['plan_status' => 'gratis']));
        $response->assertInertia(fn ($page) => $page
            ->has('users.data', 1)
            ->where('users.data.0.id', $client->id)
        );
    }

    public function test_the_subscriptions_list_can_be_sorted_by_soonest_expiry(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $plusPlan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'plus')->firstOrFail();

        $expiresSoon = User::factory()->create(['role' => 'conductor']);
        DriverProfile::factory()->for($expiresSoon)->create();
        Subscription::factory()->for($expiresSoon)->create([
            'subscription_plan_id' => $plusPlan->id,
            'status' => 'active',
            'expires_at' => now()->addDays(2),
        ]);

        $expiresLater = User::factory()->create(['role' => 'conductor']);
        DriverProfile::factory()->for($expiresLater)->create();
        Subscription::factory()->for($expiresLater)->create([
            'subscription_plan_id' => $plusPlan->id,
            'status' => 'active',
            'expires_at' => now()->addDays(20),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.subscriptions.index', [
            'role' => 'conductor',
            'sort' => 'expiry',
            'direction' => 'asc',
        ]));

        $response->assertInertia(fn ($page) => $page
            ->where('users.data.0.id', $expiresSoon->id)
            ->where('users.data.1.id', $expiresLater->id)
        );
    }

    public function test_admin_metrics_page_reports_the_plan_breakdown(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $plusPlan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'plus')->firstOrFail();
        Subscription::factory()->for($driver)->create([
            'subscription_plan_id' => $plusPlan->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.metrics.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Metrics')
            ->where('totals.drivers', 1)
        );
    }
}
