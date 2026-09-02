<?php

namespace Tests\Feature\Fleet;

use App\Models\Cooperative;
use App\Models\CooperativeDriverMembership;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\RideRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Corrección explícita del usuario sobre una primera versión de esta
 * feature: TODO plan de conductor, incluido el gratuito, ya trae su propia
 * capacidad de clientes privados configurada (`SubscriptionPlan.max_clients`
 * — el plan "gratis" hoy permite 5, no cero). "Acceso cooperativa" nunca
 * significó "cero clientes privados hasta pagar un plan" — un conductor
 * afiliado a una cooperativa puede tener cartera privada igual que
 * cualquier otro, limitada únicamente por el cupo REAL de su propio plan
 * (el mismo mecanismo que ya existía en FleetInvitationManager::accept(),
 * sin ningún límite nuevo inventado para esta feature).
 */
class CooperativeDriverPrivateClientCapacityTest extends TestCase
{
    use RefreshDatabase;

    private function cooperativeDriver(): User
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
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

        return $driver;
    }

    public function test_a_cooperative_driver_on_the_free_plan_can_still_be_invited_and_accept(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = $this->cooperativeDriver();

        $this->actingAs($client)
            ->post(route('fleet.invitations.store', $fleet), ['driver_user_id' => $driver->id])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $invitation = \App\Models\FleetInvitation::firstOrFail();

        $this->actingAs($driver)
            ->post(route('driver.invitations.accept', $invitation))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('fleet_members', ['fleet_id' => $fleet->id, 'driver_user_id' => $driver->id]);
    }

    /**
     * Misma capacidad, mismo mecanismo, esté o no afiliado a una cooperativa
     * — el plan "gratis" (sembrado con max_clients = 5 al momento de este
     * test) es la única fuente de verdad, nunca un número reinventado acá.
     */
    public function test_reaching_the_plans_real_client_capacity_blocks_further_acceptances_for_a_cooperative_driver(): void
    {
        $driver = $this->cooperativeDriver();
        $limit = (int) app(\App\Services\PlanLimits::class)->forDriver($driver)['max_clients'];

        for ($i = 0; $i < $limit; $i++) {
            $client = User::factory()->create();
            $fleet = Fleet::factory()->for($client, 'owner')->create();
            FleetMember::query()->create([
                'fleet_id' => $fleet->id,
                'driver_user_id' => $driver->id,
                'added_by' => $client->id,
                'joined_at' => now(),
            ]);
        }

        $oneMoreClient = User::factory()->create();
        $oneMoreFleet = Fleet::factory()->for($oneMoreClient, 'owner')->create();
        $this->actingAs($oneMoreClient)
            ->post(route('fleet.invitations.store', $oneMoreFleet), ['driver_user_id' => $driver->id])
            ->assertSessionHasNoErrors();

        $invitation = \App\Models\FleetInvitation::firstOrFail();

        $this->actingAs($driver)
            ->post(route('driver.invitations.accept', $invitation))
            ->assertSessionHasErrors('invitation');

        $this->assertDatabaseMissing('fleet_members', ['fleet_id' => $oneMoreFleet->id, 'driver_user_id' => $driver->id]);
    }

    /**
     * La regla anti-captación no depende del plan — sigue aplicando aunque
     * el conductor esté en el plan gratuito y tenga cupo de sobra.
     */
    public function test_the_anti_capture_rule_still_applies_regardless_of_plan(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = $this->cooperativeDriver();

        RideRequest::factory()->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'cooperative_id' => CooperativeDriverMembership::activeCooperativeFor($driver->id)->id,
            'status' => 'accepted',
        ]);

        $this->actingAs($client)
            ->post(route('fleet.invitations.store', $fleet), ['driver_user_id' => $driver->id])
            ->assertSessionHasErrors('driver_user_id');

        $this->assertDatabaseCount('fleet_invitations', 0);
    }

    public function test_driver_access_resolver_exposes_the_real_plan_capacity(): void
    {
        $driver = $this->cooperativeDriver();

        $access = app(\App\Services\Driver\DriverAccessResolver::class)->for($driver);
        $planLimit = (int) app(\App\Services\PlanLimits::class)->forDriver($driver)['max_clients'];

        $this->assertSame($planLimit, $access['private_clients']['limit']);
        $this->assertSame(0, $access['private_clients']['current']);
        $this->assertTrue($access['private_clients']['can_add']);
        $this->assertSame('Gratis', $access['plan']['name']);
    }
}
