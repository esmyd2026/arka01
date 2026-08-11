<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Verificación de teléfono por WhatsApp (consideración de seguridad agregada
 * al alcance): estos tests activan la integración a mano (Http::fake) para
 * cubrir el camino "configurado" — sin credenciales reales en .env, el resto
 * de la suite pasa por el camino "no configurado" (auto-verificado, ver
 * RegistrationTest).
 */
class PhoneVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function enableWhatsApp(): void
    {
        Config::set('services.whatsapp.token', 'fake-token');
        Config::set('services.whatsapp.phone_number_id', '123456');
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.fake']]], 200)]);
    }

    // Integración configurada, pero el envío en sí falla de verdad (token
    // vencido, límite de Meta, plantilla no aprobada, etc.) — distinto de
    // "no configurada" (WhatsAppVerificationSender::enabled() === false).
    private function enableWhatsAppButFailToSend(): void
    {
        Config::set('services.whatsapp.token', 'fake-token');
        Config::set('services.whatsapp.phone_number_id', '123456');
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid token']], 401)]);
    }

    public function test_registering_with_whatsapp_configured_requires_verification_before_reaching_the_dashboard(): void
    {
        $this->enableWhatsApp();

        $this->post('/register', [
            'account_type' => 'cliente',
            'name' => 'Test User',
            'email' => 'test@example.com',
            'country_code' => '+593',
            'phone_local' => '991234567',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $user = User::where('email', 'test@example.com')->firstOrFail();
        $this->assertNull($user->phone_verified_at);

        $response = $this->actingAs($user)->get(route('dashboard'));
        $response->assertRedirect(route('phone.verify.show'));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com'));
    }

    /**
     * Bug crítico reportado por el usuario: cuentas creadas que se quedaban
     * trabadas para siempre esperando un código que nunca llegaba porque el
     * envío fallaba de verdad (no por falta de configuración). Ahora, igual
     * que "no configurada", el teléfono queda auto-verificado.
     */
    public function test_registering_when_the_whatsapp_send_actually_fails_does_not_block_the_dashboard(): void
    {
        $this->enableWhatsAppButFailToSend();

        $this->post('/register', [
            'account_type' => 'cliente',
            'name' => 'Test User',
            'email' => 'test@example.com',
            'country_code' => '+593',
            'phone_local' => '991234567',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $user = User::where('email', 'test@example.com')->firstOrFail();
        $this->assertNotNull($user->phone_verified_at);
        $this->assertNull($user->phone_verification_code);

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    }

    /**
     * La salida real para quien YA está trabado hoy en producción: tocar
     * "Reenviar código" una vez desplegado este fix lo desbloquea solo,
     * aunque el envío vuelva a fallar.
     */
    public function test_resending_when_the_whatsapp_send_actually_fails_unblocks_the_account(): void
    {
        $this->enableWhatsAppButFailToSend();

        $user = User::factory()->unverifiedPhone()->create();

        $response = $this->actingAs($user)->post(route('phone.verify.resend'));

        $response->assertRedirect();
        $this->assertNotNull($user->fresh()->phone_verified_at);

        $this->actingAs($user->fresh())->get(route('dashboard'))->assertOk();
    }

    public function test_the_correct_code_verifies_the_phone_and_unlocks_the_dashboard(): void
    {
        $this->enableWhatsApp();

        $user = User::factory()->unverifiedPhone()->create();
        $code = $user->issuePhoneVerificationCode();

        $response = $this->actingAs($user)->post(route('phone.verify.store'), ['code' => $code]);

        $response->assertRedirect();
        $this->assertNotNull($user->fresh()->phone_verified_at);
    }

    public function test_an_incorrect_code_is_rejected(): void
    {
        $this->enableWhatsApp();

        $user = User::factory()->unverifiedPhone()->create();
        $user->issuePhoneVerificationCode();

        $response = $this->actingAs($user)->post(route('phone.verify.store'), ['code' => '000000']);

        $response->assertSessionHasErrors('code');
        $this->assertNull($user->fresh()->phone_verified_at);
    }

    public function test_an_expired_code_is_rejected(): void
    {
        $this->enableWhatsApp();

        $user = User::factory()->unverifiedPhone()->create();
        $code = $user->issuePhoneVerificationCode();
        $user->forceFill(['phone_verification_expires_at' => now()->subMinute()])->save();

        $response = $this->actingAs($user)->post(route('phone.verify.store'), ['code' => $code]);

        $response->assertSessionHasErrors('code');
    }

    public function test_a_user_without_a_phone_is_never_asked_to_verify(): void
    {
        // Cuentas creadas solo por Google no tienen teléfono (sección de login con Google).
        $user = User::factory()->create(['phone' => null, 'phone_verified_at' => null]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
    }
}
