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

    /**
     * Bug real reportado por el usuario: Referral/Show.vue mandaba
     * `driver.user_id` (el id interno, un entero) como `ref` al registro,
     * pero RegisteredUserController exige un UUID (public_id) — todo
     * registro por este link fallaba en silencio, sin ningún error visible,
     * dejando a la persona trabada en el último paso del formulario
     * (contraseña). El prop correcto para armar ese link es `public_id`.
     */
    public function test_the_landing_page_exposes_the_drivers_public_id_for_the_registration_link(): void
    {
        $driver = User::factory()->create();
        $profile = DriverProfile::factory()->for($driver)->create();

        $response = $this->get(route('referrals.show', $profile->invite_code));

        $response->assertInertia(fn ($page) => $page->where('driver.public_id', $driver->public_id));
    }

    /**
     * Reproduce el bug de punta a punta: registrarse con el `ref` que
     * Referral/Show.vue realmente manda (el public_id del conductor) tiene
     * que funcionar sin errores de validación.
     */
    public function test_registering_with_the_drivers_public_id_as_ref_succeeds(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $response = $this->post(route('register'), [
            'account_type' => 'cliente',
            'first_name' => 'Ana',
            'last_name' => 'Cliente',
            'email' => 'ana.cliente@example.com',
            'country_code' => '+593',
            'phone_local' => '991234567',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'ref' => $driver->public_id,
        ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'ana.cliente@example.com']);
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
