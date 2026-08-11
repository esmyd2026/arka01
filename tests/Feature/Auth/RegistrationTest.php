<?php

namespace Tests\Feature\Auth;

use App\Mail\WelcomeMail;
use App\Models\DriverProfile;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'account_type' => 'cliente',
            'name' => 'Test User',
            'email' => 'test@example.com',
            // El teléfono es obligatorio desde que se agregó como dato clave
            // para buscar/invitar usuarios a una flota (sección 3.2), y ahora
            // se manda en dos partes (código de país + número local, sin el
            // 0 inicial — mismo formato que se le pide al usuario en el form).
            'country_code' => '+593',
            'phone_local' => '990001111',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(RouteServiceProvider::HOME);
    }

    /**
     * Registro guiado (consideración agregada al alcance): elegir "conductor"
     * en el primer paso lleva directo a completar el perfil de conductor
     * (sección 9.5-B) en vez de dejarlo en el Inicio sin poder recibir
     * carreras todavía.
     */
    public function test_choosing_driver_account_type_redirects_to_the_driver_profile_setup(): void
    {
        $response = $this->post('/register', [
            'account_type' => 'conductor',
            'name' => 'Conductor Nuevo',
            'email' => 'conductor.nuevo@example.com',
            'country_code' => '+593',
            'phone_local' => '998888888',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('driver.profile.edit'));
    }

    /**
     * Usuario y código de socio (consideración agregada al alcance): se
     * generan solos, y el teléfono queda auto-verificado porque WhatsApp no
     * está configurado en el entorno de tests (mismo criterio que Google).
     */
    public function test_registration_assigns_a_username_and_member_code_and_auto_verifies_the_phone(): void
    {
        $this->post('/register', [
            'account_type' => 'cliente',
            'name' => 'Juan Pérez',
            'email' => 'juan@example.com',
            'country_code' => '+593',
            'phone_local' => '991234567',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $user = User::where('email', 'juan@example.com')->firstOrFail();

        $this->assertSame('jperez', $user->username);
        $this->assertGreaterThanOrEqual(500, $user->member_code);
        $this->assertNotNull($user->phone_verified_at);
        $this->assertSame('+593991234567', $user->phone);
    }

    /**
     * Pedido explícito del usuario ("existe alguna plantilla que se envía al
     * registro"): no existía ninguna — ver App\Mail\WelcomeMail.
     */
    public function test_registering_sends_a_welcome_email(): void
    {
        Mail::fake();

        $this->post('/register', [
            'account_type' => 'cliente',
            'name' => 'Juan Pérez',
            'email' => 'juan@example.com',
            'country_code' => '+593',
            'phone_local' => '991234567',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $user = User::where('email', 'juan@example.com')->firstOrFail();

        Mail::assertSent(WelcomeMail::class, fn ($mail) => $mail->hasTo($user->email) && $mail->user->is($user));
    }

    // Pedido explícito del usuario (con capturas): antes cualquier cadena de
    // 7 a 10 dígitos pasaba, incluidos números obviamente falsos como
    // 9999999999 o 090000000 — ver App\Rules\ValidPhoneNumberLocal.

    private function registerWith(string $countryCode, string $phoneLocal): TestResponse
    {
        return $this->post('/register', [
            'account_type' => 'cliente',
            'name' => 'Juan Pérez',
            'email' => 'juan@example.com',
            'country_code' => $countryCode,
            'phone_local' => $phoneLocal,
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);
    }

    public function test_an_ecuadorian_number_with_10_digits_is_rejected(): void
    {
        $this->registerWith('+593', '9900011112')->assertSessionHasErrors('phone_local');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_an_ecuadorian_number_not_starting_with_9_is_rejected(): void
    {
        $this->registerWith('+593', '090001111')->assertSessionHasErrors('phone_local');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_an_obviously_fake_repeated_digit_ecuadorian_number_is_rejected(): void
    {
        $this->registerWith('+593', '999999999')->assertSessionHasErrors('phone_local');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_valid_ecuadorian_mobile_number_is_accepted(): void
    {
        $this->registerWith('+593', '992345671')->assertRedirect(RouteServiceProvider::HOME);
        $this->assertDatabaseCount('users', 1);
    }

    /**
     * Para el resto de países no tenemos el formato exacto de cada uno —
     * la validación estricta (9 dígitos, empieza en 9) es solo para
     * Ecuador, el mercado real de la app.
     */
    public function test_a_non_ecuadorian_number_still_uses_the_looser_format_check(): void
    {
        $this->registerWith('+51', '9876543')->assertRedirect(RouteServiceProvider::HOME);
        $this->assertDatabaseCount('users', 1);
    }

    /**
     * Trazabilidad de referidos (pedido explícito del usuario): quién
     * compartió el enlace que trajo a esta cuenta nueva — ver
     * User::referredBy()/referrals().
     */
    public function test_registering_with_a_ref_records_who_referred_the_new_account(): void
    {
        $referrer = User::factory()->create();

        $this->post('/register', [
            'account_type' => 'cliente',
            'name' => 'Juan Pérez',
            'email' => 'juan@example.com',
            'country_code' => '+593',
            'phone_local' => '991234567',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'ref' => $referrer->id,
        ]);

        $user = User::where('email', 'juan@example.com')->firstOrFail();

        $this->assertSame($referrer->id, $user->referred_by_user_id);
    }

    /**
     * Pedido explícito del usuario ("que se una a su flota"): si el registro
     * vino del enlace de invitación de un conductor, se vuelve a esa
     * pantalla para completar el único paso que falta — agregarlo — en vez
     * de dejarlo en el Inicio sin rumbo.
     */
    public function test_registering_from_a_driver_referral_link_redirects_back_to_add_the_driver(): void
    {
        $driver = User::factory()->create();
        $driverProfile = DriverProfile::factory()->for($driver)->create();

        $response = $this->post('/register', [
            'account_type' => 'cliente',
            'name' => 'Juan Pérez',
            'email' => 'juan@example.com',
            'country_code' => '+593',
            'phone_local' => '991234567',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'ref' => $driver->id,
        ]);

        $response->assertRedirect(route('referrals.show', $driverProfile->invite_code));
    }
}
