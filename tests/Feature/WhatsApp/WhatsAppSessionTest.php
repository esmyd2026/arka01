<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\SendWhatsAppNumberAlreadyRegisteredNotice;
use App\Jobs\SendWhatsAppPhoneMismatchNotice;
use App\Jobs\SendWhatsAppSessionRecoveryPrompt;
use App\Jobs\SendWhatsAppWindowConfirmation;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Services\WhatsAppFreeformSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Ventana de 24h de WhatsApp (pedido explícito del usuario): el webhook
 * entrante abre la ventana cuando alguien le escribe al número oficial, y el
 * estado (activa/próxima a vencer/expirada) se calcula contra `expires_at`.
 */
class WhatsAppSessionTest extends TestCase
{
    use RefreshDatabase;

    private function enableWebhook(string $token = 'secreto-de-prueba'): void
    {
        Config::set('services.whatsapp.webhook_verify_token', $token);
    }

    public function test_the_verification_handshake_echoes_the_challenge_with_the_right_token(): void
    {
        $this->enableWebhook();

        $response = $this->get('/api/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=secreto-de-prueba&hub_challenge=12345');

        $response->assertOk();
        $response->assertSee('12345');
    }

    public function test_the_verification_handshake_rejects_the_wrong_token(): void
    {
        $this->enableWebhook();

        $response = $this->get('/api/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=algo-distinto&hub_challenge=12345');

        $response->assertForbidden();
    }

    public function test_an_inbound_message_from_a_known_phone_opens_a_session(): void
    {
        $user = User::factory()->create(['phone' => '+593991234567']);

        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'messages' => [
                            ['from' => '593991234567', 'text' => ['body' => 'Hola'], 'type' => 'text'],
                        ],
                    ],
                ]],
            ]],
        ];

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();

        $this->assertDatabaseHas('whatsapp_sessions', ['user_id' => $user->id]);
        $this->assertTrue($user->fresh()->hasActiveWhatsAppSession());
    }

    /**
     * Pedido explícito del usuario: el "bot" le confirma al conductor que ya
     * quedó conectado, apenas se abre la ventana.
     */
    public function test_an_inbound_message_dispatches_the_window_confirmation_job(): void
    {
        Queue::fake();
        $user = User::factory()->create(['phone' => '+593991234567']);

        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => ['messages' => [['from' => '593991234567', 'text' => ['body' => 'Hola'], 'type' => 'text']]],
                ]],
            ]],
        ];

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();

        Queue::assertPushed(SendWhatsAppWindowConfirmation::class, fn ($job) => $job->driverUserId === $user->id);
    }

    /**
     * Pedido explícito del usuario: en vez de que "Pedir código" sea el
     * primer paso, el widget de sesión única en Auth/Login.vue ahora invita
     * a escribir primero al WhatsApp oficial con esta frase exacta — el
     * "bot" tiene que reaccionar distinto (mandarlo de vuelta a la web),
     * no con la confirmación genérica de "activarme" pensada para conductores.
     */
    public function test_the_session_recovery_phrase_dispatches_a_different_confirmation_job(): void
    {
        Queue::fake();
        $user = User::factory()->create(['phone' => '+593991234567']);

        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => ['messages' => [['from' => '593991234567', 'text' => ['body' => 'Necesito recuperar mi sesión'], 'type' => 'text']]],
                ]],
            ]],
        ];

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();

        Queue::assertPushed(SendWhatsAppSessionRecoveryPrompt::class, fn ($job) => $job->userId === $user->id);
        Queue::assertNotPushed(SendWhatsAppWindowConfirmation::class);
    }

    /**
     * La comparación no puede depender de la tilde exacta — el navegador o
     * WhatsApp podrían normalizar el texto distinto.
     */
    public function test_the_session_recovery_phrase_matches_without_the_accent(): void
    {
        Queue::fake();
        $user = User::factory()->create(['phone' => '+593991234567']);

        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => ['messages' => [['from' => '593991234567', 'text' => ['body' => 'necesito RECUPERAR MI SESION porfa'], 'type' => 'text']]],
                ]],
            ]],
        ];

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();

        Queue::assertPushed(SendWhatsAppSessionRecoveryPrompt::class, fn ($job) => $job->userId === $user->id);
    }

    public function test_the_window_confirmation_actually_sends_a_whatsapp_message(): void
    {
        Config::set('services.whatsapp.token', 'fake-token');
        Config::set('services.whatsapp.phone_number_id', '123456');
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.fake']]], 200)]);

        $driver = User::factory()->create(['phone' => '+593991234567']);
        WhatsAppSession::query()->create(['user_id' => $driver->id, 'opened_at' => now(), 'expires_at' => now()->addHours(24)]);

        WhatsAppFreeformSender::sendWindowConfirmation($driver);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com')
            && $request['type'] === 'text'
            && $request['to'] === '593991234567');
    }

    /**
     * Pedido explícito del usuario: si el número no matchea pero el mensaje
     * trae la referencia del "Conectar WhatsApp" (ver Utils/whatsapp.js), y
     * ese usuario todavía no tiene ningún teléfono declarado, este mensaje
     * sirve como prueba de que el número es suyo — se completa solo.
     */
    public function test_a_reference_tagged_message_fills_in_a_missing_phone_and_opens_the_session(): void
    {
        Queue::fake();
        $driver = User::factory()->create(['phone' => null]);

        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => ['messages' => [[
                        'from' => '593991234567',
                        'text' => ['body' => "Buen día, inicio mi turno en Arka01 🚗 (ref:{$driver->id})"],
                        'type' => 'text',
                    ]]],
                ]],
            ]],
        ];

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();

        $this->assertSame('+593991234567', $driver->fresh()->phone);
        $this->assertDatabaseHas('whatsapp_sessions', ['user_id' => $driver->id]);
        Queue::assertPushed(SendWhatsAppWindowConfirmation::class, fn ($job) => $job->driverUserId === $driver->id);
    }

    /**
     * Pedido explícito del usuario ("si el número es diferente, indicale"):
     * si ya tiene un teléfono declarado y no coincide, no se abre la ventana
     * a su nombre — se le avisa al número que acaba de escribir.
     */
    public function test_a_reference_tagged_message_from_a_different_phone_does_not_open_a_session(): void
    {
        Queue::fake();
        $driver = User::factory()->create(['phone' => '+593999999999']);

        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => ['messages' => [[
                        'from' => '593991234567',
                        'text' => ['body' => "Buen día, inicio mi turno en Arka01 🚗 (ref:{$driver->id})"],
                        'type' => 'text',
                    ]]],
                ]],
            ]],
        ];

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();

        $this->assertSame('+593999999999', $driver->fresh()->phone);
        $this->assertDatabaseCount('whatsapp_sessions', 0);
        Queue::assertPushed(SendWhatsAppPhoneMismatchNotice::class, fn ($job) => $job->intendedDriverUserId === $driver->id
            && $job->fromE164 === '+593991234567');
    }

    /**
     * Caso real reportado por el usuario (visto en la app: dos conductores
     * distintos "conectando" el mismo número, cada uno con su propia
     * referencia): el número ya es de otra cuenta — no se abre la ventana a
     * nombre de quien lo intenta de nuevo, se le avisa por qué.
     */
    public function test_a_reference_tagged_message_from_a_number_already_owned_by_another_account_is_rejected(): void
    {
        Queue::fake();
        $phoneOwner = User::factory()->create(['phone' => '+593991234567']);
        $otherUser = User::factory()->create(['phone' => null]);

        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => ['messages' => [[
                        'from' => '593991234567',
                        'text' => ['body' => "Buen día, inicio mi turno en Arka01 🚗 (ref:{$otherUser->id})"],
                        'type' => 'text',
                    ]]],
                ]],
            ]],
        ];

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();

        $this->assertNull($otherUser->fresh()->phone);
        $this->assertDatabaseCount('whatsapp_sessions', 0);
        Queue::assertPushed(SendWhatsAppNumberAlreadyRegisteredNotice::class, fn ($job) => $job->toE164 === '+593991234567');
        Queue::assertNotPushed(SendWhatsAppWindowConfirmation::class);
    }

    /**
     * El dueño real del número sigue pudiendo conectarse normalmente aunque
     * su mensaje traiga SU PROPIA referencia (ej. reenvío del mismo link).
     */
    public function test_a_reference_tagged_message_matching_the_phone_owner_opens_the_session_normally(): void
    {
        Queue::fake();
        $phoneOwner = User::factory()->create(['phone' => '+593991234567']);

        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => ['messages' => [[
                        'from' => '593991234567',
                        'text' => ['body' => "Buen día, inicio mi turno en Arka01 🚗 (ref:{$phoneOwner->id})"],
                        'type' => 'text',
                    ]]],
                ]],
            ]],
        ];

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();

        $this->assertDatabaseHas('whatsapp_sessions', ['user_id' => $phoneOwner->id]);
        Queue::assertPushed(SendWhatsAppWindowConfirmation::class);
    }

    public function test_a_reference_to_a_nonexistent_user_does_not_error(): void
    {
        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => ['messages' => [[
                        'from' => '593991234567',
                        'text' => ['body' => 'Buen día, inicio mi turno en Arka01 🚗 (ref:999999)'],
                        'type' => 'text',
                    ]]],
                ]],
            ]],
        ];

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();

        $this->assertDatabaseCount('whatsapp_sessions', 0);
    }

    public function test_the_phone_mismatch_notice_sends_to_the_number_that_just_wrote(): void
    {
        Config::set('services.whatsapp.token', 'fake-token');
        Config::set('services.whatsapp.phone_number_id', '123456');
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.fake']]], 200)]);

        $driver = User::factory()->create(['phone' => '+593999999999']);

        WhatsAppFreeformSender::sendPhoneMismatchNotice('+593991234567', $driver);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com')
            && $request['to'] === '593991234567');
    }

    public function test_an_inbound_message_from_an_unknown_phone_is_ignored_without_error(): void
    {
        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'messages' => [
                            ['from' => '593900000000', 'text' => ['body' => 'Hola'], 'type' => 'text'],
                        ],
                    ],
                ]],
            ]],
        ];

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();

        $this->assertDatabaseCount('whatsapp_sessions', 0);
    }

    public function test_a_status_update_payload_without_messages_does_not_error(): void
    {
        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'statuses' => [['id' => 'wamid.x', 'status' => 'delivered']],
                    ],
                ]],
            ]],
        ];

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();

        $this->assertDatabaseCount('whatsapp_sessions', 0);
    }

    public function test_session_status_reflects_expiry(): void
    {
        $user = User::factory()->create();

        $active = WhatsAppSession::query()->create([
            'user_id' => $user->id, 'opened_at' => now(), 'expires_at' => now()->addHours(10),
        ]);
        $this->assertSame('active', $active->status());

        $expiringSoon = WhatsAppSession::query()->create([
            'user_id' => $user->id, 'opened_at' => now()->subHours(23), 'expires_at' => now()->addHour(),
        ]);
        $this->assertSame('expiring_soon', $expiringSoon->status());

        $expired = WhatsAppSession::query()->create([
            'user_id' => $user->id, 'opened_at' => now()->subDays(2), 'expires_at' => now()->subHour(),
        ]);
        $this->assertSame('expired', $expired->status());
    }

    public function test_current_session_is_the_most_recent_one(): void
    {
        $user = User::factory()->create();

        WhatsAppSession::query()->create(['user_id' => $user->id, 'opened_at' => now()->subDays(3), 'expires_at' => now()->subDays(2)]);
        $latest = WhatsAppSession::query()->create(['user_id' => $user->id, 'opened_at' => now(), 'expires_at' => now()->addHours(24)]);

        $this->assertSame($latest->id, $user->currentWhatsAppSession()->id);
        $this->assertTrue($user->hasActiveWhatsAppSession());
    }
}
