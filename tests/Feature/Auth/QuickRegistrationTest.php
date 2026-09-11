<?php

namespace Tests\Feature\Auth;

use App\Mail\QuickRegistrationCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Registro rápido (pedido explícito del usuario, "que simplemente sea con
 * el numero de telefono... y que cuando inicien le permita actualizar su
 * nombre y apellido y listo") — SOLO cliente: teléfono -> código (WhatsApp,
 * o por correo si dejó uno y WhatsApp no funcionó) -> completar nombre.
 *
 * Pedido explícito del usuario ("priorizar el teléfono pero si no que sea
 * por email... y si falla el envío que le diga un botón no me llegó el
 * mensaje y que lo deje pasar igual pero que le pida una contraseña"): ya no
 * hay bypass automático cuando WhatsApp falla — el registro siempre llega al
 * paso de código, con correo como respaldo automático y
 * finishWithoutCode() como escape manual final.
 */
class QuickRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function enableWhatsApp(): void
    {
        Config::set('services.whatsapp.token', 'fake-token');
        Config::set('services.whatsapp.phone_number_id', '123456');
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.fake']]], 200)]);
    }

    public function test_sending_the_code_creates_a_pending_account_and_does_not_log_in_yet(): void
    {
        $this->enableWhatsApp();

        $response = $this->postJson(route('quick-registration.send-code'), [
            'country_code' => '+593',
            'phone_local' => '991234567',
        ]);

        $response->assertOk()->assertJson(['sent_via' => 'whatsapp']);
        $this->assertGuest();

        $user = User::where('phone', '+593991234567')->firstOrFail();
        $this->assertNull($user->profile_name_completed_at);
        $this->assertNull($user->phone_verified_at);
        $this->assertNotNull($user->phone_verification_code);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com'));
    }

    /**
     * Sin WhatsApp configurado y sin un correo real que dejar como respaldo,
     * el registro sigue llegando al paso de código igual (para que el
     * "no me llegó" / finishWithoutCode() sea explícito, no automático).
     */
    public function test_without_whatsapp_configured_and_no_real_email_no_channel_is_used(): void
    {
        Mail::fake();

        $response = $this->postJson(route('quick-registration.send-code'), [
            'country_code' => '+593',
            'phone_local' => '991234567',
        ]);

        $response->assertOk()->assertJson(['sent_via' => null]);
        $this->assertGuest();

        $user = User::where('phone', '+593991234567')->firstOrFail();
        $this->assertNull($user->phone_verified_at);
        Mail::assertNothingSent();
    }

    /**
     * Pedido explícito del usuario ("priorizar el teléfono pero si no que
     * sea por email"): si dejó un correo real y WhatsApp no está configurado,
     * el código se manda por ahí en su lugar.
     */
    public function test_without_whatsapp_configured_but_with_a_real_email_it_sends_by_email(): void
    {
        Mail::fake();

        $response = $this->postJson(route('quick-registration.send-code'), [
            'country_code' => '+593',
            'phone_local' => '991234567',
            'email' => 'cliente.real@example.com',
        ]);

        $response->assertOk()->assertJson(['sent_via' => 'email']);

        $user = User::where('phone', '+593991234567')->firstOrFail();
        $this->assertSame('cliente.real@example.com', $user->email);
        Mail::assertSent(QuickRegistrationCodeMail::class, fn ($mail) => $mail->hasTo($user->email) && $mail->user->is($user));
    }

    /**
     * Mismo bug ya cubierto en PhoneVerificationTest: integración configurada
     * pero el envío en sí falla de verdad — acá, en vez de dejarlo trabado o
     * loguearlo sin ninguna prueba, se cae al respaldo por correo si tiene uno.
     */
    public function test_when_the_whatsapp_send_actually_fails_it_falls_back_to_email(): void
    {
        Config::set('services.whatsapp.token', 'fake-token');
        Config::set('services.whatsapp.phone_number_id', '123456');
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid token']], 401)]);
        Mail::fake();

        $response = $this->postJson(route('quick-registration.send-code'), [
            'country_code' => '+593',
            'phone_local' => '991234567',
            'email' => 'cliente.real@example.com',
        ]);

        $response->assertOk()->assertJson(['sent_via' => 'email']);
        $this->assertGuest();
        Mail::assertSent(QuickRegistrationCodeMail::class);
    }

    public function test_a_placeholder_email_is_never_used_as_a_fallback_channel(): void
    {
        Mail::fake();

        // Sin 'email' en el payload, la cuenta queda con el correo de
        // relleno (@sinemail.arka01.local) — jamás se le manda nada ahí.
        $this->postJson(route('quick-registration.send-code'), [
            'country_code' => '+593',
            'phone_local' => '991234567',
        ]);

        Mail::assertNothingSent();
    }

    public function test_an_email_already_used_by_another_account_is_rejected(): void
    {
        User::factory()->create(['email' => 'ya@arka01.test']);

        $response = $this->postJson(route('quick-registration.send-code'), [
            'country_code' => '+593',
            'phone_local' => '991234567',
            'email' => 'ya@arka01.test',
        ]);

        $response->assertJsonValidationErrors('email');
        $this->assertDatabaseMissing('users', ['phone' => '+593991234567']);
    }

    public function test_a_phone_with_a_real_completed_account_is_rejected(): void
    {
        User::factory()->create(['phone' => '+593991234567']);

        $response = $this->postJson(route('quick-registration.send-code'), [
            'country_code' => '+593',
            'phone_local' => '991234567',
        ]);

        $response->assertJsonValidationErrors('phone_local');
        $this->assertDatabaseCount('users', 1);
    }

    /**
     * Si la primera vez no llegó a escribir el código a tiempo, ese teléfono
     * no puede quedar bloqueado para siempre — se reusa la cuenta a medias en
     * vez de rechazarla como duplicada.
     */
    public function test_an_abandoned_quick_registration_reuses_the_same_account_instead_of_rejecting_the_phone(): void
    {
        $this->enableWhatsApp();

        $this->postJson(route('quick-registration.send-code'), [
            'country_code' => '+593',
            'phone_local' => '991234567',
        ])->assertOk();

        $firstUserId = User::where('phone', '+593991234567')->firstOrFail()->id;

        $response = $this->postJson(route('quick-registration.send-code'), [
            'country_code' => '+593',
            'phone_local' => '991234567',
        ]);

        $response->assertOk()->assertJson(['sent_via' => 'whatsapp']);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($firstUserId, User::where('phone', '+593991234567')->firstOrFail()->id);
    }

    public function test_the_correct_code_logs_in_and_redirects_to_complete_the_profile(): void
    {
        $this->enableWhatsApp();

        $this->postJson(route('quick-registration.send-code'), [
            'country_code' => '+593',
            'phone_local' => '991234567',
        ]);

        $user = User::where('phone', '+593991234567')->firstOrFail();

        // El código real no viaja en la respuesta JSON (nunca se le devuelve
        // al frontend, solo por WhatsApp) — se reemite uno conocido para
        // poder probar la confirmación sin depender del canal externo.
        $knownCode = $user->issuePhoneVerificationCode();

        $response = $this->post(route('quick-registration.verify'), [
            'phone' => '+593991234567',
            'code' => $knownCode,
        ]);

        $response->assertRedirect(route('complete-profile.show'));
        $this->assertAuthenticated();
        $this->assertNotNull($user->fresh()->phone_verified_at);
    }

    public function test_an_incorrect_code_is_rejected_and_does_not_log_in(): void
    {
        $this->enableWhatsApp();

        $this->postJson(route('quick-registration.send-code'), [
            'country_code' => '+593',
            'phone_local' => '991234567',
        ]);

        $response = $this->post(route('quick-registration.verify'), [
            'phone' => '+593991234567',
            'code' => '000000',
        ]);

        $response->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_dashboard_redirects_to_complete_profile_until_the_name_is_set(): void
    {
        $user = User::factory()->create(['profile_name_completed_at' => null]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertRedirect(route('complete-profile.show'));
    }

    public function test_completing_the_profile_clears_the_gate_and_unlocks_the_dashboard(): void
    {
        $user = User::factory()->create(['profile_name_completed_at' => null, 'name' => 'Nuevo usuario']);

        $this->actingAs($user)->post(route('complete-profile.store'), [
            'first_name' => 'Laura',
            'last_name' => 'Mendoza',
        ])->assertRedirect();

        $fresh = $user->fresh();
        $this->assertNotNull($fresh->profile_name_completed_at);
        $this->assertSame('Laura', $fresh->name);
        $this->assertSame('Mendoza', $fresh->last_name);

        $this->actingAs($fresh)->get(route('dashboard'))->assertOk();
    }

    public function test_visiting_complete_profile_when_already_complete_redirects_home(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('complete-profile.show'));

        $response->assertRedirect();
        $response->assertLocation(route('dashboard'));
    }

    /**
     * Pedido explícito del usuario: "que le diga un botón no me llegó el
     * mensaje y que lo deje pasar igual pero que le pida una contraseña" —
     * el escape final cuando ni WhatsApp ni correo funcionaron (o el código
     * nunca llegó de verdad).
     */
    public function test_finishing_without_a_code_sets_a_password_verifies_the_phone_and_logs_in(): void
    {
        $this->postJson(route('quick-registration.send-code'), [
            'country_code' => '+593',
            'phone_local' => '991234567',
        ]);

        $response = $this->post(route('quick-registration.finish-without-code'), [
            'phone' => '+593991234567',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $response->assertRedirect(route('complete-profile.show'));
        $this->assertAuthenticated();

        $user = User::where('phone', '+593991234567')->firstOrFail();
        $this->assertNotNull($user->phone_verified_at);
        $this->assertNotNull($user->password_set_at);
    }

    public function test_finishing_without_a_code_requires_a_valid_password(): void
    {
        $this->postJson(route('quick-registration.send-code'), [
            'country_code' => '+593',
            'phone_local' => '991234567',
        ]);

        $response = $this->post(route('quick-registration.finish-without-code'), [
            'phone' => '+593991234567',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertGuest();
        $this->assertNull(User::where('phone', '+593991234567')->firstOrFail()->phone_verified_at);
    }

    public function test_finishing_without_a_code_is_rejected_for_an_already_verified_account(): void
    {
        User::factory()->create(['phone' => '+593991234567']);

        $response = $this->post(route('quick-registration.finish-without-code'), [
            'phone' => '+593991234567',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertGuest();
    }

    public function test_finishing_without_a_code_is_rejected_for_an_unknown_phone(): void
    {
        $response = $this->post(route('quick-registration.finish-without-code'), [
            'phone' => '+593991234567',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertGuest();
    }
}
