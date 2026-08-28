<?php

namespace Tests\Feature\Auth;

use App\Jobs\ResolveRegistrationNeighborhood;
use App\Mail\WelcomeMail;
use App\Models\City;
use App\Models\DriverProfile;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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
     * Bug real reportado por el usuario ("luego que el cliente va a su
     * perfil aparecen en el nombre los dos"): `name` y `last_name` tienen
     * que quedar separados de verdad — `User::getFullNameAttribute()` ya se
     * encarga de combinarlos para mostrar, no hace falta (ni conviene)
     * juntarlos al guardar.
     */
    public function test_registration_keeps_first_name_and_last_name_in_separate_columns(): void
    {
        $this->post('/register', [
            'account_type' => 'cliente',
            'first_name' => 'Laura',
            'last_name' => 'Mendoza',
            'email' => 'laura.mendoza@example.com',
            'country_code' => '+593',
            'phone_local' => '990001112',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertRedirect(RouteServiceProvider::HOME);

        $this->assertDatabaseHas('users', [
            'email' => 'laura.mendoza@example.com',
            'name' => 'Laura',
            'last_name' => 'Mendoza',
        ]);
        $this->assertSame('Laura Mendoza', User::where('email', 'laura.mendoza@example.com')->first()->full_name);
    }

    public function test_registration_requires_both_first_name_and_last_name(): void
    {
        $this->post('/register', [
            'account_type' => 'cliente',
            'first_name' => 'Laura',
            'email' => 'solo.nombre@example.com',
            'country_code' => '+593',
            'phone_local' => '990001113',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertSessionHasErrors('last_name');
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

        // Bug reportado por el usuario: "se estan registrando como
        // conductor y el sistema termina creandole como cliente" — si no
        // completa el segundo paso, esto es lo único que distingue a esta
        // cuenta de un cliente normal (ver EnsureDriverOnboardingIsComplete).
        $user = User::where('email', 'conductor.nuevo@example.com')->firstOrFail();
        $this->assertTrue($user->intends_to_drive);
        $this->assertFalse($user->isDriver());
    }

    /**
     * Mismo bug: si abandona el segundo paso y vuelve más tarde (o navega a
     * /dashboard directo), tiene que volver a mandarlo a terminar en vez de
     * dejarlo operar como cliente sin darse cuenta de que le falta algo.
     */
    public function test_an_incomplete_driver_registration_is_sent_back_to_finish_from_the_dashboard(): void
    {
        $this->post('/register', [
            'account_type' => 'conductor',
            'name' => 'Conductor Abandonado',
            'email' => 'conductor.abandonado@example.com',
            'country_code' => '+593',
            'phone_local' => '998888887',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $response = $this->get(route('dashboard'));

        $response->assertRedirect(route('driver.profile.edit'));
    }

    /**
     * Apenas de verdad completa el perfil de conductor, la señal se apaga
     * y /dashboard vuelve a andar normal (ver DriverProfile::booted()).
     */
    public function test_completing_the_driver_profile_clears_the_pending_flag(): void
    {
        $user = User::factory()->create(['intends_to_drive' => true]);
        DriverProfile::factory()->for($user)->create();

        $this->assertFalse($user->fresh()->intends_to_drive);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
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
     * Bug real reportado por el usuario: una cuenta de Google no puede
     * cambiar su contraseña porque no conoce la actual (al azar, ver
     * GoogleAuthController) — acá SÍ acaba de elegir la suya, así que
     * `password_set_at` queda marcado desde el vamos.
     */
    public function test_registration_marks_that_the_account_has_its_own_password(): void
    {
        $this->post('/register', [
            'account_type' => 'cliente',
            'name' => 'Con Contraseña',
            'email' => 'concontrasena@example.com',
            'country_code' => '+593',
            'phone_local' => '991114444',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $this->assertNotNull(User::where('email', 'concontrasena@example.com')->firstOrFail()->password_set_at);
    }

    /**
     * Pedido explícito del usuario: "ver de dónde se registran las personas,
     * por su ubicación" — si el navegador dio permiso de ubicación, se
     * guarda la coordenada real y se resuelve la ciudad más cercana del
     * catálogo, sin que la persona tenga que elegirla a mano.
     */
    public function test_registration_with_location_permission_sets_the_nearest_city(): void
    {
        Bus::fake();

        // El catálogo real de ciudades ya viene cargado desde las
        // migraciones (sección "zonas del Ecuador") — se limpia acá para
        // que la ciudad más cercana sea, sin ambigüedad, la que arma este
        // test, sin depender de qué tan cerca esté alguna ciudad real.
        City::query()->delete();
        $quito = City::query()->create(['name' => 'Quito', 'province' => 'Pichincha', 'lat' => -0.1807, 'lng' => -78.4678, 'is_active' => true]);
        City::query()->create(['name' => 'Guayaquil', 'province' => 'Guayas', 'lat' => -2.1894, 'lng' => -79.8891, 'is_active' => true]);

        $this->post('/register', [
            'account_type' => 'cliente',
            'name' => 'Ubicado Cerca',
            'email' => 'ubicado@example.com',
            'country_code' => '+593',
            'phone_local' => '991112222',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'lat' => -0.19,
            'lng' => -78.47,
        ]);

        $user = User::where('email', 'ubicado@example.com')->firstOrFail();

        $this->assertSame($quito->id, $user->city_id);
        $this->assertNotNull($user->registration_lat);
        $this->assertNotNull($user->registration_lng);

        Bus::assertDispatched(ResolveRegistrationNeighborhood::class, fn ($job) => $job->userId === $user->id);
    }

    /**
     * Si el navegador niega o no soporta la ubicación, el registro sigue
     * andando igual — nunca puede quedar bloqueado por un permiso que la
     * persona puede rechazar.
     */
    public function test_registration_without_location_permission_leaves_city_unset(): void
    {
        Bus::fake();

        City::query()->create(['name' => 'Quito', 'lat' => -0.1807, 'lng' => -78.4678, 'is_active' => true]);

        $response = $this->post('/register', [
            'account_type' => 'cliente',
            'name' => 'Sin Ubicacion',
            'email' => 'sinubicacion@example.com',
            'country_code' => '+593',
            'phone_local' => '991113333',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $response->assertRedirect(RouteServiceProvider::HOME);

        $user = User::where('email', 'sinubicacion@example.com')->firstOrFail();

        $this->assertNull($user->city_id);
        $this->assertNull($user->registration_lat);

        Bus::assertNotDispatched(ResolveRegistrationNeighborhood::class);
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

    // Pedido explícito del usuario: "si pone el 09 quitemos el 0 delante" —
    // un celular de 9 dígitos con el 0 local de siempre delante (10
    // caracteres en total) se normaliza en vez de rechazarse (ver
    // App\Rules\ValidPhoneNumberLocal::normalize()) — distinto del caso de
    // arriba, que es un número mal formado de 9 caracteres, no un celular
    // real con el 0 puesto.
    public function test_a_leading_zero_on_a_real_mobile_number_is_stripped_instead_of_rejected(): void
    {
        $this->registerWith('+593', '0992345671')->assertRedirect(RouteServiceProvider::HOME);
        $this->assertDatabaseHas('users', ['phone' => '+593992345671']);
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
     * Pedido explícito del usuario ("la gente se pierde" entre iniciar
     * sesión y crear cuenta): un correo o teléfono ya registrado invita a
     * iniciar sesión en vez de solo decir "ya está en uso" — Auth/Register.vue
     * detecta este mensaje puntual para ofrecer el atajo.
     */
    public function test_registering_with_an_existing_email_invites_to_log_in(): void
    {
        $existing = User::factory()->create(['email' => 'ya@arka01.test']);

        $response = $this->post('/register', [
            'account_type' => 'cliente',
            'name' => 'Otra Persona',
            'email' => $existing->email,
            'country_code' => '+593',
            'phone_local' => '991234567',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $response->assertSessionHasErrors(['email' => 'Ya existe una cuenta con este correo. ¿Ya tiene una cuenta? Inicie sesión.']);
    }

    public function test_registering_with_an_existing_phone_invites_to_log_in(): void
    {
        User::factory()->create(['phone' => '+593991234567']);

        $response = $this->post('/register', [
            'account_type' => 'cliente',
            'name' => 'Otra Persona',
            'email' => 'otra.persona@arka01.test',
            'country_code' => '+593',
            'phone_local' => '991234567',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $response->assertSessionHasErrors(['phone_local' => 'Ese número de teléfono ya está registrado. ¿Ya tiene una cuenta? Inicie sesión.']);
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
            'ref' => $referrer->public_id,
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
            'ref' => $driver->public_id,
        ]);

        $response->assertRedirect(route('referrals.show', $driverProfile->invite_code));
    }
}
