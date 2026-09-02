<?php

namespace Tests\Feature\Driver;

use App\Models\Cooperative;
use App\Models\CooperativeDriverMembership;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Driver\DriverAccessResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pedido explícito del usuario: diferenciar el acceso "cooperativa"
 * (afiliación aceptada) del plan personal del conductor. Corrección
 * explícita sobre una primera versión de este test: TODO plan, incluido el
 * gratuito, ya trae su propia capacidad de clientes privados configurada —
 * "acceso cooperativa" nunca implicó cero clientes privados, así que los
 * permisos privados están siempre presentes; lo que varía por plan es la
 * CAPACIDAD (`private_clients`), nunca un permiso todo-o-nada.
 */
class DriverAccessResolverTest extends TestCase
{
    use RefreshDatabase;

    private function driver(): User
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        return $driver;
    }

    private function givePaidPlan(User $driver): void
    {
        $plan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'plus')->firstOrFail();
        Subscription::factory()->for($driver)->create(['subscription_plan_id' => $plan->id, 'status' => 'active']);
    }

    private function affiliate(User $driver): Cooperative
    {
        $cooperativeUser = User::factory()->create();
        $cooperative = Cooperative::query()->create(['user_id' => $cooperativeUser->id, 'name' => 'Coop Central']);
        $cooperative->forceFill(['status' => 'approved'])->save();
        CooperativeDriverMembership::query()->create([
            'cooperative_id' => $cooperative->id,
            'driver_user_id' => $driver->id,
            'invited_by_user_id' => $cooperativeUser->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);

        return $cooperative;
    }

    public function test_a_driver_with_neither_access_is_basic_but_still_has_private_client_capacity(): void
    {
        $access = app(DriverAccessResolver::class)->for($this->driver());

        $this->assertSame('basic', $access['type']);
        $this->assertFalse($access['cooperative_access']);
        $this->assertFalse($access['professional_access']);
        $this->assertNull($access['cooperative']);
        // El plan "gratis" ya trae su propia capacidad — nunca cero.
        $this->assertGreaterThan(0, $access['private_clients']['limit']);
        $this->assertTrue($access['private_clients']['can_add']);
        $this->assertContains(DriverAccessResolver::PERMISSION_PRIVATE_CLIENTS_MANAGE, $access['permissions']);
    }

    public function test_a_driver_affiliated_to_a_cooperative_keeps_their_own_plan_capacity(): void
    {
        $driver = $this->driver();
        $cooperative = $this->affiliate($driver);

        $access = app(DriverAccessResolver::class)->for($driver);

        $this->assertSame('cooperative', $access['type']);
        $this->assertTrue($access['cooperative_access']);
        $this->assertFalse($access['professional_access']);
        $this->assertSame($cooperative->id, $access['cooperative']['id']);
        $this->assertContains(DriverAccessResolver::PERMISSION_COOPERATIVE_RIDES_RECEIVE, $access['permissions']);
        // Corrección explícita del usuario: la afiliación a una cooperativa
        // NUNCA le quita al conductor la capacidad privada de su propio
        // plan — sigue pudiendo tener clientes propios hasta esa capacidad.
        $this->assertContains(DriverAccessResolver::PERMISSION_PRIVATE_CLIENTS_MANAGE, $access['permissions']);
        $this->assertGreaterThan(0, $access['private_clients']['limit']);
    }

    public function test_a_driver_with_a_paid_plan_only_gets_professional_access_and_a_bigger_capacity(): void
    {
        $driver = $this->driver();
        $this->givePaidPlan($driver);

        $access = app(DriverAccessResolver::class)->for($driver);

        $this->assertSame('professional', $access['type']);
        $this->assertFalse($access['cooperative_access']);
        $this->assertTrue($access['professional_access']);
        $this->assertNull($access['cooperative']);
        $this->assertNotContains(DriverAccessResolver::PERMISSION_COOPERATIVE_RIDES_RECEIVE, $access['permissions']);
        $this->assertSame('Plus', $access['plan']['name']);
    }

    public function test_a_driver_with_both_gets_the_combined_permission_set(): void
    {
        $driver = $this->driver();
        $this->affiliate($driver);
        $this->givePaidPlan($driver);

        $access = app(DriverAccessResolver::class)->for($driver);

        $this->assertSame('both', $access['type']);
        $this->assertTrue($access['cooperative_access']);
        $this->assertTrue($access['professional_access']);
        $this->assertContains(DriverAccessResolver::PERMISSION_COOPERATIVE_RIDES_RECEIVE, $access['permissions']);
        $this->assertContains(DriverAccessResolver::PERMISSION_PRIVATE_CLIENTS_MANAGE, $access['permissions']);
    }

    public function test_a_suspended_membership_no_longer_counts_as_cooperative_access(): void
    {
        $driver = $this->driver();
        $this->affiliate($driver);
        CooperativeDriverMembership::query()->where('driver_user_id', $driver->id)->update(['status' => 'suspended']);

        $access = app(DriverAccessResolver::class)->for($driver);

        $this->assertFalse($access['cooperative_access']);
        $this->assertSame('basic', $access['type']);
    }

    /**
     * Pedido explícito del usuario: un plan con `multi_cooperative_enabled`
     * puede dejar a un conductor afiliado a más de una cooperativa a la
     * vez — el resolver debe listarlas todas, no solo la primera.
     */
    public function test_a_driver_on_a_multi_cooperative_plan_lists_every_active_cooperative(): void
    {
        $driver = $this->driver();
        $plan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'plus')->firstOrFail();
        $plan->update(['multi_cooperative_enabled' => true]);
        Subscription::factory()->for($driver)->create(['subscription_plan_id' => $plan->id, 'status' => 'active']);

        $first = $this->affiliate($driver);
        $secondCooperativeUser = User::factory()->create();
        $second = Cooperative::query()->create(['user_id' => $secondCooperativeUser->id, 'name' => 'Coop Segunda']);
        $second->forceFill(['status' => 'approved'])->save();
        CooperativeDriverMembership::query()->create([
            'cooperative_id' => $second->id,
            'driver_user_id' => $driver->id,
            'invited_by_user_id' => $secondCooperativeUser->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);

        $access = app(DriverAccessResolver::class)->for($driver);

        $this->assertCount(2, $access['cooperatives']);
        $this->assertSame([$first->id, $second->id], array_column($access['cooperatives'], 'id'));
        $this->assertSame($first->id, $access['cooperative']['id']);
    }

    public function test_private_client_capacity_reflects_active_fleet_members_and_the_plans_limit(): void
    {
        $driver = $this->driver();
        $limit = (int) app(\App\Services\PlanLimits::class)->forDriver($driver)['max_clients'];

        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        FleetMember::query()->create([
            'fleet_id' => $fleet->id,
            'driver_user_id' => $driver->id,
            'added_by' => $client->id,
            'joined_at' => now(),
        ]);

        $access = app(DriverAccessResolver::class)->for($driver);

        $this->assertSame(1, $access['private_clients']['current']);
        $this->assertSame($limit, $access['private_clients']['limit']);
        $this->assertSame($limit - 1, $access['private_clients']['remaining']);
        $this->assertTrue($access['private_clients']['can_add']);
    }
}
