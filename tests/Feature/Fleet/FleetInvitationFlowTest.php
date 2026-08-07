<?php

namespace Tests\Feature\Fleet;

use App\Events\FleetInvitationCreated;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetInvitation;
use App\Models\FleetMember;
use App\Models\Review;
use App\Models\Ride;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Cubre el "primer caso funcional" que pide la sección 13 del alcance: el ciclo
 * completo de buscar, invitar, aceptar/rechazar y salir de una flota, más los
 * dos límites del plan Gratis (20 conductores por flota, 3 clientes por conductor).
 */
class FleetInvitationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_search_drivers_by_name_phone_or_invite_code(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = User::factory()->create(['name' => 'Juan Conductor']);
        DriverProfile::factory()->for($driver)->create();

        $response = $this->actingAs($client)
            ->getJson(route('fleet.search-drivers', ['fleet' => $fleet->id, 'q' => 'Juan']));

        $response->assertOk();
        $response->assertJsonPath('drivers.0.user_id', $driver->id);
        $response->assertJsonPath('drivers.0.status', 'not_invited');
    }

    /**
     * Pedido explícito del usuario: "saber cuántos clientes tiene ese
     * conductor" al buscarlo, para decidir si sumarse a alguien ya muy
     * repartido entre varias flotas.
     */
    public function test_search_results_include_how_many_active_clients_the_driver_already_has(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = User::factory()->create(['name' => 'Juan Conductor']);
        DriverProfile::factory()->for($driver)->create();

        $otherClientA = User::factory()->create();
        $otherFleetA = Fleet::factory()->for($otherClientA, 'owner')->create();
        FleetMember::factory()->for($otherFleetA)->for($driver, 'driver')->create(['added_by' => $otherClientA->id]);

        $otherClientB = User::factory()->create();
        $otherFleetB = Fleet::factory()->for($otherClientB, 'owner')->create();
        FleetMember::factory()->for($otherFleetB)->for($driver, 'driver')->create(['added_by' => $otherClientB->id]);

        $response = $this->actingAs($client)
            ->getJson(route('fleet.search-drivers', ['fleet' => $fleet->id, 'q' => 'Juan']));

        $response->assertJsonPath('drivers.0.active_clients_count', 2);
    }

    /**
     * Pedido explícito del usuario: foto, puntaje, cantidad de carreras y
     * medalla al buscar un conductor para invitar — antes solo se veía
     * nombre/teléfono/tarifa. La medalla pasó de basarse en la calificación a
     * basarse en puntos por carreras completadas (fidelización, ver
     * App\Models\DriverTier) — acá arranca en 0 puntos, así que es Cobre.
     */
    public function test_search_results_include_photo_rating_ride_count_and_tier(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = User::factory()->create(['name' => 'Juan Conductor']);
        DriverProfile::factory()->for($driver)->create();

        $ride = Ride::factory()->create([
            'driver_user_id' => $driver->id,
            'status' => 'completed',
        ]);
        Review::query()->create([
            'ride_id' => $ride->id,
            'reviewer_user_id' => $ride->client_user_id,
            'reviewee_user_id' => $driver->id,
            'rating' => 5,
        ]);

        $response = $this->actingAs($client)
            ->getJson(route('fleet.search-drivers', ['fleet' => $fleet->id, 'q' => 'Juan']));

        $response->assertOk();
        $response->assertJsonPath('drivers.0.review_count', 1);
        $response->assertJsonPath('drivers.0.rides_count', 1);
        $response->assertJsonPath('drivers.0.tier.name', 'Cobre');
        $this->assertArrayHasKey('avatar_url', $response->json('drivers.0'));
    }

    /**
     * Usuario y código de socio (consideración agregada al alcance): además
     * de nombre/teléfono/código de invitación, se puede buscar por acá.
     */
    public function test_client_can_search_drivers_by_username_or_member_code(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = User::factory()->create(['name' => 'Ana Conductora']);
        DriverProfile::factory()->for($driver)->create();

        $byUsername = $this->actingAs($client)
            ->getJson(route('fleet.search-drivers', ['fleet' => $fleet->id, 'q' => $driver->username]));
        $byUsername->assertJsonPath('drivers.0.user_id', $driver->id);

        $byCode = $this->actingAs($client)
            ->getJson(route('fleet.search-drivers', ['fleet' => $fleet->id, 'q' => (string) $driver->member_code]));
        $byCode->assertJsonPath('drivers.0.user_id', $driver->id);
    }

    /**
     * Antes esto no existía: el conductor no se enteraba de una invitación
     * nueva hasta refrescar la pantalla a mano.
     */
    public function test_a_new_invitation_broadcasts_to_the_driver_in_real_time(): void
    {
        Event::fake([FleetInvitationCreated::class]);

        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $this->actingAs($client)
            ->post(route('fleet.invitations.store', $fleet), ['driver_user_id' => $driver->id])
            ->assertRedirect();

        Event::assertDispatched(FleetInvitationCreated::class, fn ($event) => $event->invitation->driver_user_id === $driver->id);
    }

    public function test_client_can_invite_a_driver_and_driver_can_accept(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $this->actingAs($client)
            ->post(route('fleet.invitations.store', $fleet), ['driver_user_id' => $driver->id])
            ->assertRedirect();

        $invitation = FleetInvitation::firstOrFail();
        $this->assertSame('pending', $invitation->status);

        // El conductor acepta: recién ahí queda vinculado (nadie entra sin su consentimiento).
        $this->actingAs($driver)
            ->post(route('driver.invitations.accept', $invitation))
            ->assertRedirect();

        $this->assertDatabaseHas('fleet_invitations', [
            'id' => $invitation->id,
            'status' => 'accepted',
        ]);

        $fleet = Fleet::where('owner_user_id', $client->id)->firstOrFail();
        $this->assertTrue(
            FleetMember::where('fleet_id', $fleet->id)
                ->where('driver_user_id', $driver->id)
                ->whereNull('left_at')
                ->exists()
        );
    }

    public function test_driver_can_reject_an_invitation(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $this->actingAs($client)->post(route('fleet.invitations.store', $fleet), ['driver_user_id' => $driver->id]);
        $invitation = FleetInvitation::firstOrFail();

        $this->actingAs($driver)
            ->post(route('driver.invitations.reject', $invitation))
            ->assertRedirect();

        $this->assertDatabaseHas('fleet_invitations', [
            'id' => $invitation->id,
            'status' => 'rejected',
        ]);
        $this->assertDatabaseMissing('fleet_members', ['driver_user_id' => $driver->id]);
    }

    public function test_client_can_remove_a_driver_from_their_fleet(): void
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $member = FleetMember::factory()
            ->for($fleet)
            ->for($driver, 'driver')
            ->create(['added_by' => $client->id]);

        $this->actingAs($client)
            ->delete(route('fleet.members.destroy', $member))
            ->assertRedirect();

        $this->assertNotNull($member->fresh()->left_at);
    }

    public function test_driver_can_leave_a_fleet_voluntarily(): void
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();

        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $member = FleetMember::factory()
            ->for($fleet)
            ->for($driver, 'driver')
            ->create(['added_by' => $client->id]);

        $this->actingAs($driver)
            ->post(route('driver.fleets.leave', $member))
            ->assertRedirect();

        $this->assertNotNull($member->fresh()->left_at);
    }

    public function test_client_cannot_exceed_max_drivers_per_fleet_on_free_plan(): void
    {
        SubscriptionPlan::query()
            ->where('owner_type', 'client')->where('code', 'gratis')
            ->update(['max_drivers_per_fleet' => 1]);

        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();

        $alreadyMemberDriver = User::factory()->create();
        FleetMember::factory()
            ->for($fleet)
            ->for($alreadyMemberDriver, 'driver')
            ->create(['added_by' => $client->id]);

        $newDriver = User::factory()->create();
        DriverProfile::factory()->for($newDriver)->create();

        $this->actingAs($client)
            ->post(route('fleet.invitations.store', $fleet), ['driver_user_id' => $newDriver->id])
            ->assertSessionHasErrors('driver_user_id');

        $this->assertDatabaseMissing('fleet_invitations', ['driver_user_id' => $newDriver->id]);
    }

    public function test_driver_cannot_exceed_max_clients_on_free_plan(): void
    {
        SubscriptionPlan::query()
            ->where('owner_type', 'driver')->where('code', 'gratis')
            ->update(['max_clients' => 1]);

        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        // Ya pertenece a una flota, así que está en el tope de su plan Gratis.
        $existingClient = User::factory()->create();
        $existingFleet = Fleet::factory()->for($existingClient, 'owner')->create();
        FleetMember::factory()
            ->for($existingFleet)
            ->for($driver, 'driver')
            ->create(['added_by' => $existingClient->id]);

        // Un segundo cliente le manda otra invitación.
        $newClient = User::factory()->create();
        $newClientFleet = Fleet::factory()->for($newClient, 'owner')->create();
        $this->actingAs($newClient)->post(route('fleet.invitations.store', $newClientFleet), ['driver_user_id' => $driver->id]);
        $invitation = FleetInvitation::where('invited_by', $newClient->id)->firstOrFail();

        $this->actingAs($driver)
            ->post(route('driver.invitations.accept', $invitation))
            ->assertSessionHasErrors('invitation');

        $this->assertDatabaseHas('fleet_invitations', [
            'id' => $invitation->id,
            'status' => 'pending',
        ]);
    }
}
