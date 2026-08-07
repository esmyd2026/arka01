<?php

namespace Tests\Feature\Fleet;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Referí a tu conductor" (pedido explícito del usuario): un cliente
 * comparte un enlace público con el `invite_code` de su conductor para que
 * otras personas lo agreguen a su propia flota.
 */
class ReferralTest extends TestCase
{
    use RefreshDatabase;

    public function test_anyone_can_view_the_referral_landing_page_without_logging_in(): void
    {
        $driver = User::factory()->create();
        $profile = DriverProfile::factory()->for($driver)->create();

        $response = $this->get(route('referrals.show', $profile->invite_code));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('driver.name', $driver->name)
            ->where('canInvite', false)
        );
    }

    public function test_a_logged_in_client_can_send_the_invitation_from_the_referral_link(): void
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();
        $profile = DriverProfile::factory()->for($driver)->create();

        $this->actingAs($client)->post(route('referrals.store', $profile->invite_code))
            ->assertRedirect();

        $this->assertDatabaseHas('fleet_invitations', [
            'driver_user_id' => $driver->id,
            'invited_by' => $client->id,
            'status' => 'pending',
        ]);
    }

    public function test_a_driver_account_cannot_send_a_referral_invitation(): void
    {
        $referrer = User::factory()->create();
        DriverProfile::factory()->for($referrer)->create();

        $driver = User::factory()->create();
        $profile = DriverProfile::factory()->for($driver)->create();

        $this->actingAs($referrer)->post(route('referrals.store', $profile->invite_code))
            ->assertForbidden();
    }

    public function test_the_landing_page_says_the_driver_is_already_in_the_clients_fleet(): void
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();
        $profile = DriverProfile::factory()->for($driver)->create();

        $fleet = Fleet::factory()->for($client, 'owner')->create();
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        $response = $this->actingAs($client)->get(route('referrals.show', $profile->invite_code));

        $response->assertInertia(fn ($page) => $page->where('alreadyMember', true));
    }

    public function test_cannot_re_invite_a_driver_that_is_already_in_the_fleet(): void
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();
        $profile = DriverProfile::factory()->for($driver)->create();

        $fleet = Fleet::factory()->for($client, 'owner')->create();
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        $this->actingAs($client)->post(route('referrals.store', $profile->invite_code))
            ->assertSessionHasErrors('driver_user_id');
    }
}
