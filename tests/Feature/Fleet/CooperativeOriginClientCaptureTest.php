<?php

namespace Tests\Feature\Fleet;

use App\Models\ClientCooperative;
use App\Models\Cooperative;
use App\Models\CooperativeDriverMembership;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\RideRequest;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pedido explícito del usuario, el punto más delicado: cuando un cliente
 * conoció a un conductor a través de una carrera de COOPERATIVA
 * (RideRequest.cooperative_id no nulo), ese conductor nunca puede convertir
 * a ese cliente en cliente privado propio — ni siquiera con plan profesional
 * activo. La relación comercial de esa carrera es de la cooperativa. Si el
 * cliente y el conductor YA tenían una relación de flota privada por otra
 * vía anterior, no se bloquea (no es una captación nueva).
 */
class CooperativeOriginClientCaptureTest extends TestCase
{
    use RefreshDatabase;

    private function driverWithPaidPlan(): User
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $plan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'plus')->firstOrFail();
        Subscription::factory()->for($driver)->create(['subscription_plan_id' => $plan->id, 'status' => 'active']);

        return $driver;
    }

    private function cooperativeRideBetween(User $client, User $driver): void
    {
        $cooperativeUser = User::factory()->create();
        $cooperative = Cooperative::query()->create(['user_id' => $cooperativeUser->id, 'name' => 'Coop Central']);
        $cooperative->forceFill(['status' => 'approved'])->save();

        RideRequest::factory()->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'cooperative_id' => $cooperative->id,
            'status' => 'accepted',
        ]);
    }

    public function test_a_client_cannot_privately_recruit_a_driver_met_through_a_cooperative_ride(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = $this->driverWithPaidPlan();
        $this->cooperativeRideBetween($client, $driver);

        $this->actingAs($client)
            ->post(route('fleet.invitations.store', $fleet), ['driver_user_id' => $driver->id])
            ->assertSessionHasErrors('driver_user_id');

        $this->assertDatabaseCount('fleet_invitations', 0);
    }

    public function test_a_driver_with_a_prior_private_link_can_still_be_invited_despite_a_cooperative_ride(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = $this->driverWithPaidPlan();

        // Ya eran flota privada de antes (por ejemplo, el conductor se salió
        // y el cliente lo reinvita) — la relación no nació de la carrera de
        // cooperativa, así que no debe bloquearse.
        FleetMember::query()->create([
            'fleet_id' => $fleet->id,
            'driver_user_id' => $driver->id,
            'added_by' => $client->id,
            'joined_at' => now()->subMonths(2),
            'left_at' => now()->subMonth(),
            'left_reason' => 'driver_left',
        ]);

        $this->cooperativeRideBetween($client, $driver);

        $this->actingAs($client)
            ->post(route('fleet.invitations.store', $fleet), ['driver_user_id' => $driver->id])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('fleet_invitations', [
            'fleet_id' => $fleet->id,
            'driver_user_id' => $driver->id,
            'status' => 'pending',
        ]);
    }

    public function test_a_private_ride_with_no_cooperative_never_triggers_the_block(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = $this->driverWithPaidPlan();

        RideRequest::factory()->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'cooperative_id' => null,
            'status' => 'accepted',
        ]);

        $this->actingAs($client)
            ->post(route('fleet.invitations.store', $fleet), ['driver_user_id' => $driver->id])
            ->assertSessionHasNoErrors()
            ->assertRedirect();
    }

    /**
     * Pedido explícito del usuario ("asegurate que no se tome los clientes
     * de la cooperativa a la que tiene afiliación... los que tiene la
     * cooperativa el no los puede tomar"): el bloqueo no depende de que ya
     * hayan compartido una carrera — basta con que el cliente tenga
     * agregada a su red la cooperativa a la que este conductor está
     * afiliado, porque esa cartera ya es de la cooperativa.
     */
    public function test_a_driver_cannot_privately_recruit_a_client_already_in_his_cooperatives_network(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = $this->driverWithPaidPlan();

        $cooperativeUser = User::factory()->create();
        $cooperative = Cooperative::query()->create(['user_id' => $cooperativeUser->id, 'name' => 'Coop Amazonas']);
        $cooperative->forceFill(['status' => 'approved'])->save();
        CooperativeDriverMembership::query()->create([
            'cooperative_id' => $cooperative->id,
            'driver_user_id' => $driver->id,
            'invited_by_user_id' => $cooperativeUser->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);
        ClientCooperative::query()->create(['client_user_id' => $client->id, 'cooperative_id' => $cooperative->id]);

        $this->actingAs($client)
            ->post(route('fleet.invitations.store', $fleet), ['driver_user_id' => $driver->id])
            ->assertSessionHasErrors('driver_user_id');

        $this->assertDatabaseCount('fleet_invitations', 0);
    }

    /**
     * Pedido explícito del usuario ("le apreto invitar y no funciona... pero
     * deberia aparecer luis pero no el boton de invitar en ese caso"): antes
     * de este fix el botón "Invitar" aparecía igual y fallaba recién al
     * tocarlo — ahora la búsqueda ya marca al conductor como
     * "cooperative_locked" para que el front no ofrezca el botón.
     */
    public function test_the_client_search_flags_a_captured_driver_without_offering_the_invite_button(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = $this->driverWithPaidPlan();
        $this->cooperativeRideBetween($client, $driver);

        $response = $this->actingAs($client)
            ->getJson(route('fleet.search-drivers', ['fleet' => $fleet->id, 'q' => (string) $driver->member_code]));

        $response->assertOk();
        $response->assertJsonPath('drivers.0.user_id', $driver->id);
        $response->assertJsonPath('drivers.0.status', 'cooperative_locked');
    }

    /**
     * Pedido explícito del usuario ("asi mismo con luis que si le aparece
     * que le diga este cliente pertenece a tu flota de cooperativa"): mismo
     * criterio, del lado del conductor buscando clientes.
     */
    public function test_the_driver_search_flags_a_captured_client_without_offering_the_request_button(): void
    {
        $client = User::factory()->create();
        $driver = $this->driverWithPaidPlan();
        $this->cooperativeRideBetween($client, $driver);

        $response = $this->actingAs($driver)
            ->getJson(route('driver.clients.search', ['q' => (string) $client->member_code]));

        $response->assertOk();
        $response->assertJsonPath('clients.0.user_id', $client->id);
        $response->assertJsonPath('clients.0.status', 'cooperative_locked');
    }

    public function test_a_driver_with_a_prior_private_link_can_still_recruit_a_client_from_his_cooperatives_network(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = $this->driverWithPaidPlan();

        FleetMember::query()->create([
            'fleet_id' => $fleet->id,
            'driver_user_id' => $driver->id,
            'added_by' => $client->id,
            'joined_at' => now()->subMonths(2),
            'left_at' => now()->subMonth(),
            'left_reason' => 'driver_left',
        ]);

        $cooperativeUser = User::factory()->create();
        $cooperative = Cooperative::query()->create(['user_id' => $cooperativeUser->id, 'name' => 'Coop Amazonas']);
        $cooperative->forceFill(['status' => 'approved'])->save();
        CooperativeDriverMembership::query()->create([
            'cooperative_id' => $cooperative->id,
            'driver_user_id' => $driver->id,
            'invited_by_user_id' => $cooperativeUser->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);
        ClientCooperative::query()->create(['client_user_id' => $client->id, 'cooperative_id' => $cooperative->id]);

        $this->actingAs($client)
            ->post(route('fleet.invitations.store', $fleet), ['driver_user_id' => $driver->id])
            ->assertSessionHasNoErrors()
            ->assertRedirect();
    }
}
