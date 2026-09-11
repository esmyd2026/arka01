<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Login sin contraseña por WhatsApp (pedido explícito del usuario) — para
 * CUALQUIER cuenta con teléfono verificado, no solo las del registro rápido.
 * Mismo criterio de respuesta genérica que SessionTakeoverTest: nunca delata
 * si la cuenta existe, ni si tiene teléfono verificado.
 */
class PhoneLoginTest extends TestCase
{
    use RefreshDatabase;

    private function enableWhatsApp(): void
    {
        Config::set('services.whatsapp.token', 'fake-token');
        Config::set('services.whatsapp.phone_number_id', '123456');
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.fake']]], 200)]);
    }

    private function fakeOtherDeviceSession(User $user): void
    {
        DB::table('sessions')->insert([
            'id' => Str::random(40),
            'user_id' => $user->id,
            'ip_address' => '10.0.0.9',
            'user_agent' => 'otro-dispositivo',
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->subMinute()->getTimestamp(),
        ]);
    }

    public function test_requesting_a_code_for_an_unknown_login_gives_a_generic_response_and_sends_nothing(): void
    {
        $this->enableWhatsApp();

        $this->postJson(route('phone-login.request'), ['login' => '+593999999999'])->assertOk();

        Http::assertNothingSent();
    }

    public function test_requesting_a_code_for_an_account_without_a_verified_phone_sends_nothing(): void
    {
        $this->enableWhatsApp();
        $user = User::factory()->unverifiedPhone()->create(['phone' => '+593991234567']);

        $this->postJson(route('phone-login.request'), ['login' => $user->email])->assertOk();

        Http::assertNothingSent();
        $this->assertNull($user->fresh()->login_code);
    }

    public function test_requesting_a_code_for_a_locked_account_sends_nothing(): void
    {
        $this->enableWhatsApp();
        $user = User::factory()->create(['phone' => '+593991234567', 'locked_at' => now()]);

        $this->postJson(route('phone-login.request'), ['login' => $user->phone])->assertOk();

        Http::assertNothingSent();
    }

    public function test_requesting_a_code_for_a_verified_phone_sends_it_by_whatsapp(): void
    {
        $this->enableWhatsApp();
        $user = User::factory()->create(['phone' => '+593991234567']);

        $this->postJson(route('phone-login.request'), ['login' => $user->phone])->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com')
            && $request['to'] === '593991234567');
        $this->assertNotNull($user->fresh()->login_code);
    }

    public function test_the_correct_code_logs_in_and_redirects_home(): void
    {
        $this->enableWhatsApp();
        $user = User::factory()->create(['phone' => '+593991234567']);
        $code = $user->issueLoginCode();

        $response = $this->post(route('phone-login.confirm'), ['login' => $user->phone, 'code' => $code]);

        $response->assertRedirect();
        $this->assertAuthenticatedAs($user->fresh());
        $this->assertNull($user->fresh()->login_code);
    }

    public function test_an_incorrect_code_is_rejected(): void
    {
        $user = User::factory()->create(['phone' => '+593991234567']);
        $user->issueLoginCode();

        $response = $this->post(route('phone-login.confirm'), ['login' => $user->phone, 'code' => '000000']);

        $response->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_an_expired_code_is_rejected(): void
    {
        $user = User::factory()->create(['phone' => '+593991234567']);
        $code = $user->issueLoginCode();
        $user->forceFill(['login_code_expires_at' => now()->subMinute()])->save();

        $response = $this->post(route('phone-login.confirm'), ['login' => $user->phone, 'code' => $code]);

        $response->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_a_locked_account_cannot_log_in_even_with_a_previously_issued_code(): void
    {
        $user = User::factory()->create(['phone' => '+593991234567']);
        $code = $user->issueLoginCode();
        $user->forceFill(['locked_at' => now()])->save();

        $response = $this->post(route('phone-login.confirm'), ['login' => $user->phone, 'code' => $code]);

        $response->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    /**
     * Sesión única por cuenta (pedido explícito del usuario): el login por
     * código tiene que respetar la misma regla que el login con contraseña.
     */
    public function test_login_is_blocked_by_an_existing_active_session_on_another_device(): void
    {
        $user = User::factory()->create(['phone' => '+593991234567']);
        $this->fakeOtherDeviceSession($user);
        $code = $user->issueLoginCode();

        $response = $this->post(route('phone-login.confirm'), ['login' => $user->phone, 'code' => $code]);

        $response->assertSessionHasErrors('code');
        $this->assertGuest();
    }
}
