<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Models\WhatsAppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests de Seguridad - Manejo de Secretos
 */
class SecretsSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_whatsapp_token_is_encrypted(): void
    {
        $setting = WhatsAppSetting::factory()->create([
            'token' => 'secret_token_12345',
        ]);

        // Verificar que en la BD está cifrado
        $rawFromDb = DB::table('whatsapp_settings')
            ->where('id', $setting->id)
            ->first();

        $this->assertNotEquals('secret_token_12345', $rawFromDb->token);
        $this->assertStringNotContainsString('secret_token_12345', $rawFromDb->token);
    }

    public function test_whatsapp_token_not_exposed_in_json(): void
    {
        $setting = WhatsAppSetting::factory()->create([
            'token' => 'secret_token_12345',
        ]);

        $json = $setting->toJson();

        $this->assertStringNotContainsString('secret_token_12345', $json);
    }

    public function test_phone_verification_code_is_hashed(): void
    {
        $user = User::factory()->create();

        $code = $user->issuePhoneVerificationCode();

        $this->assertTrue(password_verify($code, $user->fresh()->phone_verification_code));
        $this->assertArrayNotHasKey('phone_verification_code', $user->toArray());
    }

    public function test_google_id_hidden_in_json(): void
    {
        $user = User::factory()->create([
            'google_id' => '123456789',
        ]);

        $json = json_decode($user->toJson(), true);

        $this->assertArrayNotHasKey('google_id', $json);
    }

    /**
     * Ya está en `User::$hidden` (auditoría de seguridad anterior, ver el
     * comentario en app/Models/User.php) — acá se deja la prueba de verdad
     * en vez de darlo por sentado.
     */
    public function test_session_takeover_code_is_hidden_in_json(): void
    {
        $user = User::factory()->create([
            'session_takeover_code' => 'secret_code_123',
        ]);

        $json = $user->toJson();

        $this->assertStringNotContainsString('secret_code_123', $json);
        $this->assertArrayNotHasKey('session_takeover_code', json_decode($json, true));
    }
}
