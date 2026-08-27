<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\DriverProfile;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\WhatsAppSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    /**
     * Pedido explícito del usuario: "un botón que le invite a escribirle al
     * chatbot de arka01 para que de allí tomemos el número y que ellos
     * puedan estar notificados de sus viajes" — Profile/Edit.vue decide con
     * estos dos datos si ofrece el botón (solo a clientes, no a
     * conductores ni admins — ese filtro vive del lado del template).
     */
    public function test_the_profile_page_exposes_whatsapp_connection_data(): void
    {
        Config::set('services.whatsapp.business_number', '+593991112222');
        $client = User::factory()->create();

        $response = $this->actingAs($client)->get('/profile');

        $response->assertInertia(fn ($page) => $page
            ->where('whatsappBusinessNumber', '+593991112222')
            ->where('whatsappSession', null)
        );
    }

    public function test_the_profile_page_reports_the_active_whatsapp_session(): void
    {
        Config::set('services.whatsapp.business_number', '+593991112222');
        $client = User::factory()->create();
        WhatsAppSession::query()->create(['user_id' => $client->id, 'opened_at' => now(), 'expires_at' => now()->addHours(20)]);

        $response = $this->actingAs($client)->get('/profile');

        $response->assertInertia(fn ($page) => $page->where('whatsappSession.status', 'active'));
    }

    /**
     * Trazabilidad de referidos (pedido explícito del usuario): tabla de
     * quiénes se registraron a través de un enlace que este usuario
     * compartió — ver User::referrals().
     */
    public function test_profile_page_lists_the_users_referrals(): void
    {
        $referrer = User::factory()->create();
        $referred = User::factory()->create(['name' => 'Ana Referida', 'referred_by_user_id' => $referrer->id]);
        User::factory()->create(); // alguien sin relación, no debe aparecer

        $response = $this->actingAs($referrer)->get('/profile');

        $response->assertInertia(fn ($page) => $page
            ->has('referrals', 1)
            ->where('referrals.0.id', $referred->id)
            ->where('referrals.0.name', 'Ana Referida')
        );
    }

    /**
     * "Mi suscripción" (consideración agregada al alcance): un conductor sin
     * flota ve solo el resumen de conductor, con el próximo plan como upsell.
     */
    public function test_profile_shows_the_driver_subscription_summary_with_the_next_plan_upsell(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $nextPlan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'basico')->firstOrFail();

        $response = $this->actingAs($driver)->get('/profile');

        $response->assertInertia(fn ($page) => $page
            ->where('subscriptionSummary.driver.current.plan_code', 'gratis')
            ->where('subscriptionSummary.driver.next.name', $nextPlan->name)
            ->missing('subscriptionSummary.client')
        );
    }

    /**
     * Reporte del usuario: Gratis y Plus de cliente comparten `max_fleets`
     * (ambos 1) y solo difieren en `max_drivers_per_fleet` — el texto de
     * upsell no debía decir "hasta 0 flota(s) más".
     */
    public function test_profile_client_subscription_upsell_mentions_the_dimension_that_actually_improves(): void
    {
        $client = User::factory()->create();

        $response = $this->actingAs($client)->get('/profile');

        $response->assertInertia(fn ($page) => $page
            ->where('subscriptionSummary.client.current.plan_code', 'gratis')
            ->where('subscriptionSummary.client.next.name', 'Plus')
            ->where('subscriptionSummary.client.next.benefit', fn ($benefit) => str_contains($benefit, 'conductor')
                && ! str_contains($benefit, '0 flota')
            )
        );
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    /**
     * Ciudad donde vive (consideración agregada al alcance): arranca por
     * defecto la solicitud de carrera en esa ciudad.
     */
    public function test_user_can_set_their_default_city(): void
    {
        $user = User::factory()->create();
        $city = City::query()->where('name', 'Cuenca')->firstOrFail();

        $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => $user->name,
                'email' => $user->email,
                'city_id' => $city->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($city->id, $user->fresh()->city_id);
    }

    /**
     * Foto de perfil (consideración agregada al alcance): sirve tanto para
     * cuentas cliente como conductor, porque `avatar_path` vive en `users`,
     * no en `driver_profiles`.
     */
    public function test_user_can_upload_a_profile_photo(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => UploadedFile::fake()->image('foto.jpg'),
            ]);

        $response->assertSessionHasNoErrors();

        $user->refresh();
        Storage::disk('public')->assertExists($user->avatar_path);
        $this->assertNotNull($user->avatar_url);
    }

    /**
     * Pedido explícito del usuario: el mensaje de "la foto pesa demasiado"
     * salía en inglés (no existe lang/es/validation.php, Laravel cae al
     * inglés del framework para cualquier regla sin mensaje propio) — ahora
     * ProfileUpdateRequest::messages() lo cubre en español.
     */
    public function test_uploading_an_oversized_avatar_shows_a_spanish_error_message(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => UploadedFile::fake()->create('foto.jpg', 5000)->size(5000),
            ]);

        $response->assertSessionHasErrors(['avatar' => 'La foto pesa demasiado — el máximo es 4 MB. Probá con una de menor resolución o comprimida.']);
    }

    /**
     * Subir una foto nueva borra la anterior del disco — pero si la que
     * tenía era una URL externa (login con Google), no hay que intentar
     * borrar nada del disco 'public'.
     */
    public function test_uploading_a_new_avatar_deletes_the_previous_local_file(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['avatar_path' => 'avatars/old.jpg']);
        Storage::disk('public')->put('avatars/old.jpg', 'contenido');

        $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => UploadedFile::fake()->image('nueva.jpg'),
            ])
            ->assertSessionHasNoErrors();

        Storage::disk('public')->assertMissing('avatars/old.jpg');
    }

    public function test_uploading_a_new_avatar_does_not_try_to_delete_a_google_url(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['avatar_path' => 'https://lh3.googleusercontent.com/foto.jpg']);

        $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => UploadedFile::fake()->image('nueva.jpg'),
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(str_starts_with($user->fresh()->avatar_path, 'avatars/'));
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    /**
     * Pedido explícito del usuario ("nombres, apellidos, fecha de
     * nacimiento...") — mismas columnas nuevas que arman el checklist de
     * "Complete su perfil".
     */
    public function test_last_name_and_birth_date_are_saved(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => $user->name,
                'email' => $user->email,
                'last_name' => 'Pérez',
                'birth_date' => '1990-05-20',
            ])
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('Pérez', $user->last_name);
        $this->assertSame('1990-05-20', $user->birth_date->format('Y-m-d'));
    }

    /**
     * La plataforma exige mayoría de edad (ya está en los manuales, pero
     * antes no se validaba en ningún lado) — mismo umbral, 18 años.
     */
    public function test_a_birth_date_under_18_years_is_rejected(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => $user->name,
                'email' => $user->email,
                'birth_date' => now()->subYears(17)->format('Y-m-d'),
            ])
            ->assertSessionHasErrors('birth_date');
    }

    /**
     * Pedido explícito del usuario ("un puntitto rojo con un uno para que
     * vaya y actualice") — HandleInertiaRequests::share() calcula esta
     * bandera con los datos del cliente; acá se prueba el "antes" (le falta
     * de todo) y el "después" (con todo completo, incluido el teléfono
     * verificado) de un mismo cliente.
     */
    public function test_is_profile_incomplete_is_true_while_data_is_missing_and_false_once_complete(): void
    {
        $client = User::factory()->create(['last_name' => null, 'birth_date' => null, 'city_id' => null]);
        $city = City::query()->where('name', 'Cuenca')->firstOrFail();

        $this->actingAs($client)->get('/profile')
            ->assertInertia(fn ($page) => $page->where('auth.isProfileIncomplete', true));

        $client->forceFill([
            'last_name' => 'Pérez',
            'birth_date' => '1990-05-20',
            'city_id' => $city->id,
            'phone_verified_at' => now(),
        ])->save();

        $this->actingAs($client)->get('/profile')
            ->assertInertia(fn ($page) => $page->where('auth.isProfileIncomplete', false));
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }

    /**
     * Pedido explícito del usuario: "agreguemos un campo para que busquen
     * en la plataforma quien los recomendo que busquen por nombres o
     * usuario o codigo".
     */
    public function test_can_search_for_a_referrer_by_partial_name(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create(['name' => 'Gabriela Parrales']);

        $response = $this->actingAs($user)->getJson(route('profile.search-referrer', ['q' => 'Gabriela']));

        $response->assertOk();
        $response->assertJsonFragment(['id' => $target->id]);
    }

    public function test_the_search_never_includes_the_user_themselves(): void
    {
        $user = User::factory()->create(['name' => 'Gabriela Parrales']);

        $response = $this->actingAs($user)->getJson(route('profile.search-referrer', ['q' => 'Gabriela']));

        $response->assertJsonMissing(['id' => $user->id]);
    }

    /**
     * Pedido explícito del usuario: "le den a un boton guardar y ya alli se
     * quede quemado" — se guarda una sola vez.
     */
    public function test_can_save_who_referred_them(): void
    {
        $user = User::factory()->create();
        $referrer = User::factory()->create();

        $this->actingAs($user)->post(route('profile.set-referrer'), [
            'referrer_user_id' => $referrer->id,
        ])->assertRedirect();

        $this->assertSame($referrer->id, $user->fresh()->referred_by_user_id);
    }

    public function test_cannot_overwrite_an_already_saved_referrer(): void
    {
        $originalReferrer = User::factory()->create();
        $otherUser = User::factory()->create();
        $user = User::factory()->create(['referred_by_user_id' => $originalReferrer->id]);

        $this->actingAs($user)->post(route('profile.set-referrer'), [
            'referrer_user_id' => $otherUser->id,
        ])->assertSessionHasErrors('referrer_user_id');

        $this->assertSame($originalReferrer->id, $user->fresh()->referred_by_user_id);
    }

    public function test_cannot_mark_themselves_as_their_own_referrer(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('profile.set-referrer'), [
            'referrer_user_id' => $user->id,
        ])->assertSessionHasErrors('referrer_user_id');

        $this->assertNull($user->fresh()->referred_by_user_id);
    }

    public function test_the_profile_page_exposes_who_referred_them(): void
    {
        $referrer = User::factory()->create(['name' => 'Gabriela Parrales']);
        $user = User::factory()->create(['referred_by_user_id' => $referrer->id]);

        $response = $this->actingAs($user)->get('/profile');

        $response->assertInertia(fn ($page) => $page->where('referredBy.name', 'Gabriela Parrales'));
    }
}
