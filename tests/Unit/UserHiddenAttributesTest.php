<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Auditoría de seguridad (pedido explícito del usuario): estos campos no
 * deben viajar en NINGUNA serialización del modelo (Inertia::render con el
 * modelo completo, response()->json(), etc.) — is_admin queda afuera de
 * $hidden a propósito, el propio usuario lo necesita para su nav
 * (AuthenticatedLayout.vue).
 */
class UserHiddenAttributesTest extends TestCase
{
    use RefreshDatabase;

    public function test_sensitive_fields_never_serialize(): void
    {
        $user = User::factory()->create([
            'google_id' => 'google-123',
            'locked_at' => now(),
        ]);
        $user->issueSessionTakeoverCode();
        $user->issuePhoneVerificationCode();

        $array = $user->fresh()->toArray();

        $this->assertArrayNotHasKey('password', $array);
        $this->assertArrayNotHasKey('remember_token', $array);
        $this->assertArrayNotHasKey('google_id', $array);
        $this->assertArrayNotHasKey('locked_at', $array);
        $this->assertArrayNotHasKey('session_takeover_code', $array);
        $this->assertArrayNotHasKey('session_takeover_expires_at', $array);
        $this->assertArrayNotHasKey('phone_verification_code', $array);
        $this->assertArrayNotHasKey('phone_verification_expires_at', $array);

        // is_admin SÍ debe seguir visible — lo usa AuthenticatedLayout.vue
        // para la nav del propio usuario.
        $this->assertArrayHasKey('is_admin', $array);
    }
}
