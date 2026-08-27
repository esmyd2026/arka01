<?php

namespace Tests\Feature\Auth;

use App\Models\DriverProfile;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'login' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(RouteServiceProvider::HOME);
    }

    public function test_login_from_a_public_profile_attributes_the_referrer(): void
    {
        $referrer = User::factory()->create();
        $user = User::factory()->create(['referred_by_user_id' => null]);

        $this->get(route('login', ['ref' => $referrer->id]))->assertOk();

        $this->post(route('login'), [
            'login' => $user->email,
            'password' => 'password',
        ])->assertRedirect(RouteServiceProvider::HOME);

        $this->assertSame($referrer->id, $user->fresh()->referred_by_user_id);
    }

    public function test_login_from_another_profile_does_not_replace_an_existing_referrer(): void
    {
        $originalReferrer = User::factory()->create();
        $otherReferrer = User::factory()->create();
        $user = User::factory()->create(['referred_by_user_id' => $originalReferrer->id]);

        $this->get(route('login', ['ref' => $otherReferrer->id]))->assertOk();
        $this->post(route('login'), [
            'login' => $user->email,
            'password' => 'password',
        ])->assertRedirect(RouteServiceProvider::HOME);

        $this->assertSame($originalReferrer->id, $user->fresh()->referred_by_user_id);
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'login' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
        // Contraseña incorrecta para una cuenta que SÍ existe: mensaje
        // genérico de siempre, no el de "no encontramos una cuenta".
        $response->assertSessionHasErrors(['login' => trans('auth.failed')]);
    }

    /**
     * Pedido explícito del usuario ("la gente se pierde" entre iniciar
     * sesión y crear cuenta): si el dato no corresponde a ninguna cuenta, un
     * mensaje puntual que Auth/Login.vue usa para ofrecer crear una cuenta —
     * distinto del genérico que se usa cuando la cuenta sí existe pero la
     * contraseña está mal.
     */
    public function test_logging_in_with_a_nonexistent_account_offers_to_create_one(): void
    {
        $response = $this->post('/login', [
            'login' => 'nadie@arka01.test',
            'password' => 'Password123',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors(['login' => 'No encontramos una cuenta con ese dato. ¿Quiere crear una cuenta?']);
    }

    /**
     * Login múltiple (consideración agregada al alcance): además del correo,
     * el mismo campo acepta el teléfono o el usuario autogenerado.
     */
    public function test_users_can_authenticate_using_their_phone_number(): void
    {
        $user = User::factory()->create(['phone' => '+593991234567']);

        $this->post('/login', [
            'login' => $user->phone,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
    }

    public function test_users_can_authenticate_using_their_phone_without_the_country_code(): void
    {
        $user = User::factory()->create(['phone' => '+593991234567']);

        $this->post('/login', [
            'login' => '0991234567',
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
    }

    public function test_users_can_authenticate_using_their_generated_username(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'login' => $user->username,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    /**
     * Se reportó: un conductor que cerró sesión (o a quien se le venció la
     * sesión) seguía apareciendo "disponible" para su flota, aunque ni
     * siquiera estuviera logueado.
     */
    public function test_logging_out_turns_off_a_drivers_availability(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['is_available' => true]);

        $this->actingAs($driver)->post('/logout');

        $this->assertFalse($driver->driverProfile->fresh()->is_available);
    }

    public function test_logging_out_a_driver_who_is_already_unavailable_does_not_error(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['is_available' => false]);

        $this->actingAs($driver)->post('/logout')->assertRedirect('/');

        $this->assertGuest();
    }

    public function test_logging_out_a_client_does_not_error(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client)->post('/logout')->assertRedirect('/');

        $this->assertGuest();
    }
}
