<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\ResolveRegistrationNeighborhood;
use App\Models\City;
use App\Models\Cooperative;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Registro móvil (ROADMAP_APLICACION_MOVIL_CAPACITOR.md, Hito 2) — reusa
 * App\Actions\Auth\RegisterUser, la misma Action que RegisteredUserController
 * (web), así que estos tests se enfocan en lo que es específico del canal
 * móvil (forma del payload, token de respuesta) y no repiten cada caso de
 * negocio ya cubierto por tests/Feature/Auth/RegistrationTest.php.
 */
class MobileRegisterTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'account_type' => 'cliente',
            'first_name' => 'Juan',
            'last_name' => 'Pérez',
            'email' => 'juan@example.com',
            'country_code' => '+593',
            'phone_local' => '991234567',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'device_id' => (string) Str::uuid(),
            'platform' => 'android',
        ], $overrides);
    }

    public function test_a_client_can_register_and_receive_a_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->payload());

        $response->assertOk()
            ->assertJsonStructure(['token', 'token_type', 'expires_at', 'user' => ['id', 'name', 'email']])
            // Bug real reportado por el usuario ("aparecen en el nombre los
            // dos"): `name` guarda solo el nombre de pila, `last_name` el
            // apellido aparte, y `full_name` (UserResource) es la
            // combinación de ambos para mostrar.
            ->assertJsonPath('user.name', 'Juan')
            ->assertJsonPath('user.last_name', 'Pérez')
            ->assertJsonPath('user.full_name', 'Juan Pérez')
            ->assertJsonPath('user.role', 'cliente');

        $this->assertDatabaseHas('users', ['email' => 'juan@example.com', 'phone' => '+593991234567', 'name' => 'Juan', 'last_name' => 'Pérez']);
    }

    public function test_a_driver_account_type_sets_intends_to_drive(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['account_type' => 'conductor']))->assertOk();

        $user = User::where('email', 'juan@example.com')->firstOrFail();
        $this->assertTrue($user->intends_to_drive);
    }

    public function test_a_cooperative_can_register_like_on_the_web(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['account_type' => 'cooperativa']))
            ->assertOk()
            ->assertJsonPath('user.role', 'cooperativa');

        $user = User::where('email', 'juan@example.com')->firstOrFail();
        $this->assertTrue(Cooperative::query()->where('user_id', $user->id)->exists());
    }

    public function test_duplicate_email_is_rejected_with_the_same_message_as_the_web(): void
    {
        User::factory()->create(['email' => 'juan@example.com']);

        $response = $this->postJson('/api/v1/auth/register', $this->payload());

        $response->assertUnprocessable();
        $this->assertStringContainsString('Ya existe una cuenta con este correo', $response->json('message'));
    }

    public function test_duplicate_phone_is_rejected(): void
    {
        User::factory()->create(['phone' => '+593991234567']);

        $response = $this->postJson('/api/v1/auth/register', $this->payload(['email' => 'otra@example.com']));

        $response->assertUnprocessable();
        $this->assertStringContainsString('Ese número de teléfono ya está registrado', $response->json('message'));
    }

    public function test_a_leading_zero_on_the_local_phone_is_normalized(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['phone_local' => '0992345671']))->assertOk();

        $this->assertDatabaseHas('users', ['phone' => '+593992345671']);
    }

    public function test_device_id_and_platform_are_required(): void
    {
        $payload = $this->payload();
        unset($payload['device_id'], $payload['platform']);

        $this->postJson('/api/v1/auth/register', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['device_id', 'platform']);
    }

    public function test_registering_with_a_ref_records_who_referred_the_new_account(): void
    {
        $referrer = User::factory()->create();

        $this->postJson('/api/v1/auth/register', $this->payload(['ref' => $referrer->public_id]))->assertOk();

        $user = User::where('email', 'juan@example.com')->firstOrFail();
        $this->assertSame($referrer->id, $user->referred_by_user_id);
    }

    public function test_registering_with_location_sets_the_nearest_city(): void
    {
        Bus::fake();

        City::query()->delete();
        $quito = City::query()->create(['name' => 'Quito', 'province' => 'Pichincha', 'lat' => -0.1807, 'lng' => -78.4678, 'is_active' => true]);

        $this->postJson('/api/v1/auth/register', $this->payload(['lat' => -0.19, 'lng' => -78.47]))->assertOk();

        $user = User::where('email', 'juan@example.com')->firstOrFail();
        $this->assertSame($quito->id, $user->city_id);
        Bus::assertDispatched(ResolveRegistrationNeighborhood::class, fn ($job) => $job->userId === $user->id);
    }

    public function test_a_second_login_from_another_device_is_blocked_by_single_active_session(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['device_id' => 'telefono-1']))->assertOk();

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'juan@example.com',
            'password' => 'Password123',
            'device_id' => 'telefono-2',
            'platform' => 'android',
        ]);

        $response->assertUnprocessable();
        $this->assertStringContainsString('sesión activa en otro dispositivo', $response->json('message'));
    }
}
