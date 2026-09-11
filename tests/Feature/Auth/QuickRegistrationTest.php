<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Registro rápido (pedido explícito del usuario, "que simplemente sea con
 * el numero de telefono... y que cuando inicien le permita actualizar su
 * nombre y apellido y listo") — SOLO cliente: teléfono -> código de WhatsApp
 * -> completar nombre. Mismo criterio de "WhatsApp configurado/no
 * configurado/configurado pero falla" que PhoneVerificationTest.
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

        $response->assertOk()->assertJson(['verified' => false]);
        $this->assertGuest();

        $user = User::where('phone', '+593991234567')->firstOrFail();
        $this->assertNull($user->profile_name_completed_at);
        $this->assertNull($user->phone_verified_at);
        $this->assertNotNull($user->phone_verification_code);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com'));
    }

    /**
     * Sin WhatsApp configurado no hay forma de esperar un código que nunca va
     * a llegar (mismo criterio que RegisterUser::execute()) — se verifica
     * solo y se loguea de una.
     */
    public function test_without_whatsapp_configured_it_logs_in_right_away(): void
    {
        $response = $this->postJson(route('quick-registration.send-code'), [
            'country_code' => '+593',
            'phone_local' => '991234567',
        ]);

        $response->assertOk()->assertJson(['verified' => true]);
        $this->assertAuthenticated();

        $user = User::where('phone', '+593991234567')->firstOrFail();
        $this->assertNotNull($user->phone_verified_at);
    }

    /**
     * Mismo bug ya cubierto en PhoneVerificationTest: integración configurada
     * pero el envío en sí falla de verdad — no debería trabar a nadie.
     */
    public function test_when_the_whatsapp_send_actually_fails_it_logs_in_right_away(): void
    {
        Config::set('services.whatsapp.token', 'fake-token');
        Config::set('services.whatsapp.phone_number_id', '123456');
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid token']], 401)]);

        $response = $this->postJson(route('quick-registration.send-code'), [
            'country_code' => '+593',
            'phone_local' => '991234567',
        ]);

        $response->assertOk()->assertJson(['verified' => true]);
        $this->assertAuthenticated();
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

        $response->assertOk()->assertJson(['verified' => false]);
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
}
