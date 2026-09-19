<?php

namespace Tests\Feature\Api\V1;

use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Eliminación de cuenta desde la app (roadmap Hito 4) — mismo criterio que
 * ProfileController::destroy() (web): pide la contraseña actual.
 */
class MobileAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_account_is_deleted_with_the_correct_password(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/v1/account', ['password' => 'password']);

        $response->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_the_wrong_password_does_not_delete_the_account(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/v1/account', ['password' => 'incorrecta']);

        $response->assertUnprocessable();
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_deleting_the_account_revokes_every_token_not_just_the_current_one(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('telefono-1')->plainTextToken;
        $user->createToken('telefono-2');

        $this->assertSame(2, $user->tokens()->count());

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/v1/account', ['password' => 'password'])
            ->assertOk();

        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count());
    }

    public function test_deleting_the_account_turns_an_available_driver_offline_first(): void
    {
        $user = User::factory()->create(['role' => 'conductor']);
        DriverProfile::factory()->for($user)->create(['is_available' => true]);
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/v1/account', ['password' => 'password'])
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_deleting_the_account_requires_a_token(): void
    {
        $this->deleteJson('/api/v1/account', ['password' => 'password'])->assertUnauthorized();
    }

    /**
     * Bug real encontrado probando en el emulador: `deleteJson()` (como los
     * tests de arriba) llama al endpoint directo, sin pasar por el preflight
     * CORS que sí hace un WebView/navegador de verdad para un método no
     * "simple" como DELETE. `config/cors.php` tenía `allowed_methods` sin
     * incluir 'DELETE' — el botón de la app se quedaba en "Eliminando…"
     * para siempre porque el navegador bloqueaba la petición antes de
     * mandarla, sin que ningún test end-to-end normal lo detectara.
     */
    public function test_the_cors_preflight_allows_the_delete_method(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'https://localhost',
            'Access-Control-Request-Method' => 'DELETE',
        ])->options('/api/v1/account');

        $response->assertNoContent();
        $this->assertStringContainsString('DELETE', $response->headers->get('Access-Control-Allow-Methods'));
    }
}
