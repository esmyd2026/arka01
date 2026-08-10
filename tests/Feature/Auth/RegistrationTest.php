<?php

namespace Tests\Feature\Auth;

use App\Mail\WelcomeMail;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
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
            'phone_local' => '999999999',
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
}
