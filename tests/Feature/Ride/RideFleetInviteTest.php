<?php

namespace Tests\Feature\Ride;

use App\Models\Cooperative;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pedido explícito del usuario: si un conductor de cooperativa o del
 * directorio público le hizo una carrera a un cliente que todavía no lo
 * tiene en su flota, el cliente puede agregarlo directo desde el detalle de
 * la carrera — ver RideController::fleetInviteStatusForClient().
 */
class RideFleetInviteTest extends TestCase
{
    use RefreshDatabase;

    private function completedRideBetween(User $client, User $driver): Ride
    {
        // Mismo criterio que RideRequestCreator::resolveFleet(): la flota del
        // cliente ya existe para cuando llega a pedir/completar una carrera
        // (se resuelve o crea sola al pedirla) — se arma acá para no
        // depender del efecto colateral de RideController::show().
        $fleet = Fleet::where('owner_user_id', $client->id)->orderBy('id')->first()
            ?? Fleet::factory()->for($client, 'owner')->create();

        $rideRequest = RideRequest::factory()->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'accepted',
        ]);

        return Ride::factory()->create([
            'ride_request_id' => $rideRequest->id,
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    public function test_a_completed_ride_with_a_driver_not_in_the_fleet_offers_to_add_them(): void
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $ride = $this->completedRideBetween($client, $driver);

        $response = $this->actingAs($client)->get(route('rides.show', $ride));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('fleetInvite.status', 'not_invited')
            ->has('fleetInvite.fleet_id'));
    }

    public function test_the_client_can_add_the_driver_to_their_fleet_from_the_ride(): void
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $ride = $this->completedRideBetween($client, $driver);

        $fleet = Fleet::where('owner_user_id', $client->id)->firstOrFail();

        $this->actingAs($client)
            ->post(route('fleet.invitations.store', $fleet), ['driver_user_id' => $driver->id])
            ->assertRedirect();

        $this->assertDatabaseHas('fleet_invitations', [
            'fleet_id' => $fleet->id,
            'driver_user_id' => $driver->id,
            'status' => 'pending',
        ]);
    }

    public function test_a_driver_already_in_the_fleet_shows_as_a_member(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        FleetMember::query()->create([
            'fleet_id' => $fleet->id,
            'driver_user_id' => $driver->id,
            'added_by' => $client->id,
            'joined_at' => now(),
        ]);
        $ride = $this->completedRideBetween($client, $driver);

        $response = $this->actingAs($client)->get(route('rides.show', $ride));

        $response->assertInertia(fn ($page) => $page->where('fleetInvite.status', 'member'));
    }

    public function test_a_pending_invitation_shows_as_pending(): void
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $ride = $this->completedRideBetween($client, $driver);

        $fleet = Fleet::where('owner_user_id', $client->id)->firstOrFail();
        $this->actingAs($client)->post(route('fleet.invitations.store', $fleet), ['driver_user_id' => $driver->id]);

        $response = $this->actingAs($client)->get(route('rides.show', $ride));

        $response->assertInertia(fn ($page) => $page->where('fleetInvite.status', 'pending'));
    }

    /**
     * Mismo criterio anticaptura que CooperativeOriginClientCaptureTest: un
     * conductor conocido por una carrera de cooperativa no puede convertirse
     * en flota privada — el botón no debe ofrecerse acá tampoco.
     */
    public function test_a_driver_met_through_a_cooperative_ride_is_locked(): void
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $cooperativeUser = User::factory()->create();
        $cooperative = Cooperative::query()->create(['user_id' => $cooperativeUser->id, 'name' => 'Coop Central']);
        $cooperative->forceFill(['status' => 'approved'])->save();

        $rideRequest = RideRequest::factory()->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'cooperative_id' => $cooperative->id,
            'status' => 'accepted',
        ]);
        $ride = Ride::factory()->create([
            'ride_request_id' => $rideRequest->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $response = $this->actingAs($client)->get(route('rides.show', $ride));

        $response->assertInertia(fn ($page) => $page->where('fleetInvite.status', 'cooperative_locked'));
    }

    public function test_a_ride_still_in_progress_does_not_offer_to_add_the_driver(): void
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $rideRequest = RideRequest::factory()->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'accepted',
        ]);
        $ride = Ride::factory()->create([
            'ride_request_id' => $rideRequest->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'in_progress',
        ]);

        $response = $this->actingAs($client)->get(route('rides.show', $ride));

        $response->assertInertia(fn ($page) => $page->where('fleetInvite', null));
    }

    public function test_the_driver_viewing_their_own_completed_ride_gets_no_fleet_invite_prop(): void
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $ride = $this->completedRideBetween($client, $driver);

        $response = $this->actingAs($driver)->get(route('rides.show', $ride));

        $response->assertInertia(fn ($page) => $page->where('fleetInvite', null));
    }
}
