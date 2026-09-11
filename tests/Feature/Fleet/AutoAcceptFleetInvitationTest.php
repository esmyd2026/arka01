<?php

namespace Tests\Feature\Fleet;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetInvitation;
use App\Models\FleetMember;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Notifications\FleetInvitationAutoAcceptedPushNotification;
use App\Notifications\FleetInvitationPushNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Pedido explícito del usuario: la aprobación manual de invitaciones de
 * flota viene DESACTIVADA por defecto para todos los conductores ("te dije
 * que a todos les pongas por default desactivado") — con eso apagado,
 * cualquier cliente que lo agregue queda vinculado de una, solo se le avisa.
 * El conductor puede prenderla desde su perfil si prefiere aceptar cada
 * invitación a mano (ver DriverProfile::requires_fleet_invitation_approval).
 */
class AutoAcceptFleetInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_by_default_a_client_initiated_invitation_auto_accepts(): void
    {
        Notification::fake();
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $this->actingAs($client)
            ->post(route('fleet.invitations.store', $fleet), ['driver_user_id' => $driver->id])
            ->assertRedirect();

        $invitation = FleetInvitation::firstOrFail();
        $this->assertSame('accepted', $invitation->status);
        $this->assertNotNull($invitation->responded_at);

        $this->assertTrue(
            FleetMember::where('fleet_id', $fleet->id)
                ->where('driver_user_id', $driver->id)
                ->whereNull('left_at')
                ->exists()
        );

        Notification::assertSentTo($driver, FleetInvitationAutoAcceptedPushNotification::class);
        Notification::assertNotSentTo($driver, FleetInvitationPushNotification::class);
    }

    public function test_a_driver_can_turn_manual_approval_back_on(): void
    {
        Notification::fake();
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['requires_fleet_invitation_approval' => true]);

        $this->actingAs($client)
            ->post(route('fleet.invitations.store', $fleet), ['driver_user_id' => $driver->id])
            ->assertRedirect();

        $invitation = FleetInvitation::firstOrFail();
        $this->assertSame('pending', $invitation->status);
        $this->assertDatabaseMissing('fleet_members', ['driver_user_id' => $driver->id]);
        Notification::assertSentTo($driver, FleetInvitationPushNotification::class);
        Notification::assertNotSentTo($driver, FleetInvitationAutoAcceptedPushNotification::class);
    }

    /**
     * Una solicitud DEL conductor sigue necesitando el sí explícito del
     * cliente — es él quien responde en esa dirección (ver
     * FleetInvitation::respondingPartyId()), el ajuste del conductor no
     * puede saltarse el consentimiento del cliente sobre su propia flota.
     */
    public function test_a_driver_initiated_request_still_needs_the_clients_approval_even_with_auto_accept_on(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['requires_fleet_invitation_approval' => false]);
        $client = User::factory()->create();

        $this->actingAs($driver)
            ->post(route('fleet-invitations.request'), ['client_user_id' => $client->id])
            ->assertRedirect();

        $invitation = FleetInvitation::firstOrFail();
        $this->assertSame('pending', $invitation->status);
        $this->assertDatabaseMissing('fleet_members', ['driver_user_id' => $driver->id]);
    }

    public function test_auto_accept_still_respects_the_drivers_client_plan_capacity(): void
    {
        SubscriptionPlan::query()
            ->where('owner_type', 'driver')->where('code', 'gratis')
            ->update(['max_clients' => 1]);

        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['requires_fleet_invitation_approval' => false]);

        $existingClient = User::factory()->create();
        $existingFleet = Fleet::factory()->for($existingClient, 'owner')->create();
        FleetMember::factory()->for($existingFleet)->for($driver, 'driver')->create();

        $newClient = User::factory()->create();
        $newFleet = Fleet::factory()->for($newClient, 'owner')->create();

        $this->actingAs($newClient)
            ->post(route('fleet.invitations.store', $newFleet), ['driver_user_id' => $driver->id])
            ->assertSessionHasErrors('driver_user_id');

        $this->assertDatabaseCount('fleet_invitations', 0);
        $this->assertDatabaseMissing('fleet_members', ['fleet_id' => $newFleet->id, 'driver_user_id' => $driver->id]);
    }

    public function test_a_fleet_recommendation_referral_also_auto_accepts(): void
    {
        Notification::fake();
        // "Recomendar mi flota": el referente recomienda un conductor QUE YA
        // ES parte de su propia flota a un amigo (otro cliente) — ver
        // FleetInvitationManager::sendReferral().
        $referrer = User::factory()->create();
        $referrerFleet = Fleet::factory()->for($referrer, 'owner')->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['requires_fleet_invitation_approval' => false]);
        FleetMember::query()->create([
            'fleet_id' => $referrerFleet->id,
            'driver_user_id' => $driver->id,
            'added_by' => $referrer->id,
            'joined_at' => now(),
        ]);

        $friend = User::factory()->create();

        $this->actingAs($referrer)
            ->post(route('fleet.referral.store', $referrerFleet), [
                'friend_user_id' => $friend->id,
                'driver_user_ids' => [$driver->id],
            ])
            ->assertRedirect();

        $friendFleet = Fleet::where('owner_user_id', $friend->id)->firstOrFail();
        $this->assertTrue(
            FleetMember::where('fleet_id', $friendFleet->id)
                ->where('driver_user_id', $driver->id)
                ->whereNull('left_at')
                ->exists()
        );
    }
}
