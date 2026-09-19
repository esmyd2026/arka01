<?php

namespace Tests\Feature\Api\V1;

use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Login/logout de la API móvil (ROADMAP_APLICACION_MOVIL_CAPACITOR.md,
 * Hito 2) — cubre que reuse exactamente las mismas reglas que el login web:
 * credenciales, cuenta bloqueada, y sobre todo la sesión única compartida
 * con las sesiones de navegador (ver EnforceSingleActiveSession).
 */
class MobileAuthTest extends TestCase
{
    use RefreshDatabase;

    private function loginPayload(User $user, array $overrides = []): array
    {
        return array_merge([
            'login' => $user->email,
            'password' => 'password',
            'device_id' => (string) Str::uuid(),
            'device_name' => 'iPhone de prueba',
            'platform' => 'ios',
            'app_version' => '1.0.0',
        ], $overrides);
    }

    public function test_a_client_can_login_and_receive_a_token(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/v1/auth/login', $this->loginPayload($user));

        $response->assertOk()
            ->assertJsonStructure(['token', 'token_type', 'expires_at', 'user' => ['id', 'name', 'email']]);

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'platform' => 'ios',
            'app_version' => '1.0.0',
        ]);
    }

    /**
     * Bug encontrado probando la app real en un emulador Android: device_name
     * y app_version son 'nullable' en las reglas de validación — si el
     * cliente ni los manda (como haría cualquier versión mínima real, no
     * solo la prueba técnica), Laravel ni siquiera pone esas keys en el
     * array validado, y acceder a $data['device_name'] directo tiraba
     * "Undefined array key".
     */
    public function test_login_works_without_the_optional_device_name_and_app_version(): void
    {
        $user = User::factory()->create();
        $payload = $this->loginPayload($user);
        unset($payload['device_name'], $payload['app_version']);

        $this->postJson('/api/v1/auth/login', $payload)->assertOk();
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/v1/auth/login', $this->loginPayload($user, ['password' => 'incorrecta']));

        $response->assertUnprocessable()->assertJsonValidationErrors('login');
    }

    public function test_unknown_login_is_rejected_with_a_clear_message(): void
    {
        $response = $this->postJson('/api/v1/auth/login', $this->loginPayload(User::factory()->make(['email' => 'nadie@arka01.test'])));

        $response->assertUnprocessable();
        $this->assertStringContainsString('No encontramos una cuenta', $response->json('message'));
    }

    public function test_a_locked_account_cannot_login_from_mobile(): void
    {
        $user = User::factory()->create(['locked_at' => now()]);

        $response = $this->postJson('/api/v1/auth/login', $this->loginPayload($user));

        $response->assertUnprocessable();
        $this->assertStringContainsString('bloqueada', $response->json('message'));
    }

    public function test_login_is_blocked_while_a_web_session_is_active_on_another_device(): void
    {
        $user = User::factory()->create();

        DB::table('sessions')->insert([
            'id' => Str::random(40),
            'user_id' => $user->id,
            'ip_address' => '10.0.0.9',
            'user_agent' => 'navegador-de-escritorio',
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->getTimestamp(),
        ]);

        $response = $this->postJson('/api/v1/auth/login', $this->loginPayload($user));

        $response->assertUnprocessable();
        $this->assertStringContainsString('sesión activa en otro dispositivo', $response->json('message'));
    }

    public function test_a_second_device_is_blocked_while_a_mobile_token_is_active(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/login', $this->loginPayload($user, ['device_id' => 'telefono-1']))->assertOk();

        $response = $this->postJson('/api/v1/auth/login', $this->loginPayload($user, ['device_id' => 'telefono-2']));

        $response->assertUnprocessable();
        $this->assertStringContainsString('sesión activa en otro dispositivo', $response->json('message'));
    }

    public function test_relogging_in_from_the_same_device_replaces_the_old_token_instead_of_blocking(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/login', $this->loginPayload($user, ['device_id' => 'mismo-telefono']))->assertOk();

        $response = $this->postJson('/api/v1/auth/login', $this->loginPayload($user, ['device_id' => 'mismo-telefono']));

        $response->assertOk();
        $this->assertSame(1, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count());
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/auth/logout');

        $response->assertOk();
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count());
    }

    public function test_logout_turns_an_available_driver_offline(): void
    {
        $user = User::factory()->create(['role' => 'conductor']);
        DriverProfile::factory()->for($user)->create(['is_available' => true]);
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertFalse($user->driverProfile->fresh()->is_available);
    }

    public function test_me_returns_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/auth/me');

        $response->assertOk()->assertJsonPath('email', $user->email);
    }

    public function test_me_requires_a_token(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }
}
