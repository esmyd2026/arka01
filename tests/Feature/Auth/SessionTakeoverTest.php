<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Models\WhatsAppSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pedido explícito del usuario (caso real: "no sé dónde dejé loguiada mi
 * sesión y no me deja entrar desde otro navegador") — un código de un solo
 * uso (WhatsApp si tiene la ventana de 24h abierta, si no por correo) que
 * cierra todas las sesiones de la cuenta, sin esperar a que venzan solas.
 */
class SessionTakeoverTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_requesting_a_code_for_an_unknown_login_gives_the_same_generic_response(): void
    {
        Mail::fake();

        $response = $this->postJson(route('session-takeover.request'), ['login' => 'nadie@arka01.test']);

        $response->assertOk();
        Mail::assertNothingSent();
    }

    public function test_requesting_a_code_sends_it_by_email_without_an_active_whatsapp_window(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $this->fakeOtherDeviceSession($user);

        $this->postJson(route('session-takeover.request'), ['login' => $user->email])->assertOk();

        Mail::assertSent(\App\Mail\SessionTakeoverCodeMail::class, fn ($mail) => $mail->user->id === $user->id
            // Pedido explícito del usuario: "si no es usted, solicitar
            // bloquear la cuenta" — el correo tiene que traer ese link.
            && str_contains($mail->lockUrl, "/sesion/bloquear/{$user->id}"));
        $this->assertNotNull($user->fresh()->session_takeover_code);
    }

    public function test_requesting_a_code_sends_it_by_whatsapp_with_an_active_window(): void
    {
        Config::set('services.whatsapp.token', 'fake-token');
        Config::set('services.whatsapp.phone_number_id', '123456');
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.fake']]], 200)]);
        Mail::fake();

        $user = User::factory()->create(['phone' => '+593991234567']);
        WhatsAppSession::query()->create(['user_id' => $user->id, 'opened_at' => now(), 'expires_at' => now()->addHours(20)]);
        $this->fakeOtherDeviceSession($user);

        $this->postJson(route('session-takeover.request'), ['login' => $user->email])->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com')
            && $request['to'] === '593991234567');
        Mail::assertNothingSent();
    }

    public function test_confirming_the_right_code_closes_every_session_for_the_account(): void
    {
        $user = User::factory()->create();
        $this->fakeOtherDeviceSession($user);
        $this->fakeOtherDeviceSession($user);
        $code = $user->issueSessionTakeoverCode();

        $this->postJson(route('session-takeover.confirm'), ['login' => $user->email, 'code' => $code])
            ->assertOk();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertNull($user->fresh()->session_takeover_code);
    }

    public function test_confirming_the_wrong_code_does_not_close_any_session(): void
    {
        $user = User::factory()->create();
        $this->fakeOtherDeviceSession($user);
        $user->issueSessionTakeoverCode();

        $this->postJson(route('session-takeover.confirm'), ['login' => $user->email, 'code' => '000000'])
            ->assertJsonValidationErrors('code');

        $this->assertSame(1, DB::table('sessions')->where('user_id', $user->id)->count());
    }

    public function test_confirming_an_expired_code_is_rejected(): void
    {
        $user = User::factory()->create();
        $code = $user->issueSessionTakeoverCode();
        $user->forceFill(['session_takeover_expires_at' => now()->subMinute()])->save();

        $this->postJson(route('session-takeover.confirm'), ['login' => $user->email, 'code' => $code])
            ->assertJsonValidationErrors('code');
    }

    /**
     * El caso real de punta a punta: el login normal seguía bloqueado por la
     * sesión vieja, y ya puede entrar apenas confirma el código.
     */
    public function test_login_succeeds_after_a_confirmed_takeover_even_though_the_old_session_row_is_still_there(): void
    {
        $user = User::factory()->create();
        $this->fakeOtherDeviceSession($user);

        $this->post('/login', ['login' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('login');
        $this->assertGuest();

        $code = $user->issueSessionTakeoverCode();
        $this->postJson(route('session-takeover.confirm'), ['login' => $user->email, 'code' => $code])->assertOk();

        $this->post('/login', ['login' => $user->email, 'password' => 'password']);

        $this->assertAuthenticated();
    }

    /**
     * Pedido explícito del usuario ("si no es usted, solicitar bloquear la
     * cuenta"): el link firmado bloquea la cuenta de inmediato y cierra
     * todas sus sesiones — sin necesitar sesión iniciada, justo porque se
     * usa cuando la cuenta puede estar comprometida.
     */
    public function test_the_signed_lock_link_locks_the_account_and_closes_every_session(): void
    {
        $user = User::factory()->create();
        $this->fakeOtherDeviceSession($user);

        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute('session-takeover.lock', now()->addMinutes(30), ['user' => $user->id]);

        $this->get($url)->assertOk();

        $this->assertNotNull($user->fresh()->locked_at);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
    }

    public function test_a_tampered_lock_link_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->get(route('session-takeover.lock', $user->id))->assertForbidden();
        $this->assertNull($user->fresh()->locked_at);
    }

    public function test_a_locked_account_cannot_log_in_with_the_right_password(): void
    {
        $user = User::factory()->create(['locked_at' => now()]);

        $this->post('/login', ['login' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_an_admin_can_unlock_an_account(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create(['locked_at' => now()]);

        $this->actingAs($admin)->post(route('admin.users.unlock', $user))->assertRedirect();

        $this->assertNull($user->fresh()->locked_at);
    }

    public function test_a_regular_user_cannot_unlock_an_account(): void
    {
        $requester = User::factory()->create(['is_admin' => false]);
        $user = User::factory()->create(['locked_at' => now()]);

        $this->actingAs($requester)->post(route('admin.users.unlock', $user))->assertForbidden();

        $this->assertNotNull($user->fresh()->locked_at);
    }
}
