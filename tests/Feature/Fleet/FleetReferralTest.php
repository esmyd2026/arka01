<?php

namespace Tests\Feature\Fleet;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetInvitation;
use App\Models\FleetMember;
use App\Models\User;
use App\Notifications\FleetInvitationPushNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * "Recomendar mi flota" (pedido explícito del usuario): un cliente busca a un
 * amigo (otro cliente) por su usuario o código de socio, y le recomienda uno
 * o varios conductores de SU PROPIA flota — el conductor recibe una
 * invitación normal para la flota del amigo, con una etiqueta de quién lo
 * recomendó, y decide si acepta o rechaza igual que cualquier otra.
 */
class FleetReferralTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_search_a_friend_by_member_code(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $friend = User::factory()->create(['name' => 'Ana Amiga']);

        $response = $this->actingAs($client)
            ->getJson(route('fleet.referral.search-friends', ['fleet' => $fleet->id, 'q' => (string) $friend->member_code]));

        $response->assertOk();
        $response->assertJsonPath('friends.0.user_id', $friend->id);
    }

    public function test_client_can_search_a_friend_by_username_with_or_without_at_sign(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $friend = User::factory()->create();

        $this->actingAs($client)
            ->getJson(route('fleet.referral.search-friends', ['fleet' => $fleet->id, 'q' => '@'.$friend->username]))
            ->assertJsonPath('friends.0.user_id', $friend->id);

        $this->actingAs($client)
            ->getJson(route('fleet.referral.search-friends', ['fleet' => $fleet->id, 'q' => $friend->username]))
            ->assertJsonPath('friends.0.user_id', $friend->id);
    }

    public function test_search_excludes_drivers_and_myself(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $this->actingAs($client)
            ->getJson(route('fleet.referral.search-friends', ['fleet' => $fleet->id, 'q' => (string) $driver->member_code]))
            ->assertJsonCount(0, 'friends');

        $this->actingAs($client)
            ->getJson(route('fleet.referral.search-friends', ['fleet' => $fleet->id, 'q' => (string) $client->member_code]))
            ->assertJsonCount(0, 'friends');
    }

    public function test_client_can_refer_all_drivers_of_their_fleet_to_a_friend(): void
    {
        Notification::fake();

        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driverOne = User::factory()->create();
        DriverProfile::factory()->for($driverOne)->create();
        $driverTwo = User::factory()->create();
        DriverProfile::factory()->for($driverTwo)->create();
        FleetMember::factory()->for($fleet)->for($driverOne, 'driver')->create(['added_by' => $client->id]);
        FleetMember::factory()->for($fleet)->for($driverTwo, 'driver')->create(['added_by' => $client->id]);

        $friend = User::factory()->create(['name' => 'Ana Amiga']);

        $this->actingAs($client)
            ->post(route('fleet.referral.store', $fleet), [
                'friend_user_id' => $friend->id,
                'driver_user_ids' => [$driverOne->id, $driverTwo->id],
            ])
            ->assertRedirect();

        $friendFleet = Fleet::where('owner_user_id', $friend->id)->firstOrFail();

        foreach ([$driverOne, $driverTwo] as $driver) {
            $this->assertDatabaseHas('fleet_invitations', [
                'fleet_id' => $friendFleet->id,
                'driver_user_id' => $driver->id,
                'invited_by' => $client->id,
                'initiated_by' => 'referral',
                'status' => 'pending',
            ]);
            Notification::assertSentTo($driver, FleetInvitationPushNotification::class);
        }
    }

    /**
     * Solo puedo recomendar conductores que ya son parte de MI flota — no
     * cualquier usuario existente en la plataforma.
     */
    public function test_cannot_refer_a_driver_that_is_not_in_my_fleet(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $outsideDriver = User::factory()->create();
        DriverProfile::factory()->for($outsideDriver)->create();

        $friend = User::factory()->create();

        $this->actingAs($client)
            ->post(route('fleet.referral.store', $fleet), [
                'friend_user_id' => $friend->id,
                'driver_user_ids' => [$outsideDriver->id],
            ])
            ->assertSessionHasErrors('driver_user_ids');

        $this->assertDatabaseMissing('fleet_invitations', ['driver_user_id' => $outsideDriver->id]);
    }

    public function test_cannot_refer_to_myself(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        $this->actingAs($client)
            ->post(route('fleet.referral.store', $fleet), [
                'friend_user_id' => $client->id,
                'driver_user_ids' => [$driver->id],
            ])
            ->assertStatus(422);
    }

    /**
     * Un lote parcialmente inválido (un conductor ya es miembro de la flota
     * del amigo) no aborta el resto — se envían las válidas igual.
     */
    public function test_a_driver_already_in_the_friends_fleet_is_skipped_without_blocking_the_rest(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $alreadyMemberDriver = User::factory()->create();
        DriverProfile::factory()->for($alreadyMemberDriver)->create();
        $newDriver = User::factory()->create();
        DriverProfile::factory()->for($newDriver)->create();
        FleetMember::factory()->for($fleet)->for($alreadyMemberDriver, 'driver')->create(['added_by' => $client->id]);
        FleetMember::factory()->for($fleet)->for($newDriver, 'driver')->create(['added_by' => $client->id]);

        $friend = User::factory()->create();
        $friendFleet = Fleet::factory()->for($friend, 'owner')->create();
        FleetMember::factory()->for($friendFleet)->for($alreadyMemberDriver, 'driver')->create(['added_by' => $friend->id]);

        $this->actingAs($client)
            ->post(route('fleet.referral.store', $fleet), [
                'friend_user_id' => $friend->id,
                'driver_user_ids' => [$alreadyMemberDriver->id, $newDriver->id],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('fleet_invitations', [
            'fleet_id' => $friendFleet->id,
            'driver_user_id' => $newDriver->id,
            'initiated_by' => 'referral',
        ]);
        $this->assertDatabaseMissing('fleet_invitations', [
            'fleet_id' => $friendFleet->id,
            'driver_user_id' => $alreadyMemberDriver->id,
        ]);
    }

    public function test_driver_can_accept_a_referred_invitation_and_joins_the_friends_fleet(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        $friend = User::factory()->create();

        $this->actingAs($client)->post(route('fleet.referral.store', $fleet), [
            'friend_user_id' => $friend->id,
            'driver_user_ids' => [$driver->id],
        ]);

        $invitation = FleetInvitation::where('initiated_by', 'referral')->firstOrFail();

        $this->actingAs($driver)
            ->post(route('driver.invitations.accept', $invitation))
            ->assertRedirect();

        $friendFleet = Fleet::where('owner_user_id', $friend->id)->firstOrFail();
        $this->assertTrue(
            FleetMember::where('fleet_id', $friendFleet->id)
                ->where('driver_user_id', $driver->id)
                ->whereNull('left_at')
                ->exists()
        );
    }

    /**
     * Quien ve la invitación pendiente en su propia pantalla es mi amigo
     * (dueño de la flota destino), no yo — así que también puede cancelarla,
     * aunque no la haya mandado él (ver FleetInvitationPolicy::cancel()).
     */
    public function test_the_friend_who_owns_the_target_fleet_can_cancel_a_referred_invitation(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        $friend = User::factory()->create();

        $this->actingAs($client)->post(route('fleet.referral.store', $fleet), [
            'friend_user_id' => $friend->id,
            'driver_user_ids' => [$driver->id],
        ]);

        $invitation = FleetInvitation::where('initiated_by', 'referral')->firstOrFail();

        $this->actingAs($friend)
            ->delete(route('fleet.invitations.destroy', $invitation))
            ->assertRedirect();

        $this->assertDatabaseHas('fleet_invitations', ['id' => $invitation->id, 'status' => 'cancelled']);
    }
}
