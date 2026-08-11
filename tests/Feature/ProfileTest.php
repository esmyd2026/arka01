<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\DriverProfile;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
}
