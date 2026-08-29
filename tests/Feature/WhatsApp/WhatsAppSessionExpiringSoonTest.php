<?php

namespace Tests\Feature\WhatsApp;

use App\Console\Commands\NotifyExpiringWhatsAppSessions;
use App\Jobs\NotifyWhatsAppSessionExpiringSoon;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Services\WhatsAppFreeformSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Pedido explícito del usuario: avisar por WhatsApp al conductor antes de que
 * se le cierre la ventana de 24 horas, con un botón para restablecerla —
 * tocar cualquier botón ya cuenta como mensaje entrante y reabre la ventana
 * sola (WhatsAppWebhookController::openWindowFor()).
 */
class WhatsAppSessionExpiringSoonTest extends TestCase
{
    use RefreshDatabase;

    private function enableWhatsApp(): void
    {
        Config::set('services.whatsapp.token', 'fake-token');
        Config::set('services.whatsapp.phone_number_id', '123456');
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.fake']]], 200)]);
    }

    public function test_the_notice_sends_a_button_message_when_the_session_is_active(): void
    {
        $this->enableWhatsApp();
        $driver = User::factory()->create(['phone' => '+593991234567']);
        WhatsAppSession::query()->create(['user_id' => $driver->id, 'opened_at' => now(), 'expires_at' => now()->addHours(1)]);

        WhatsAppFreeformSender::sendSessionExpiringSoonNotice($driver);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com')
            && $request['type'] === 'interactive'
            && $request['interactive']['action']['buttons'][0]['reply']['id'] === 'wa_session_keepalive'
            && $request['interactive']['action']['buttons'][1]['reply']['id'] === 'wa_driver_disconnect');
    }

    public function test_the_notice_sends_nothing_without_an_active_session(): void
    {
        $this->enableWhatsApp();
        $driver = User::factory()->create(['phone' => '+593991234567']);

        WhatsAppFreeformSender::sendSessionExpiringSoonNotice($driver);

        Http::assertNothingSent();
    }

    public function test_tapping_keep_connected_confirms_without_touching_availability(): void
    {
        $this->enableWhatsApp();
        $driver = User::factory()->create(['phone' => '+593991234567']);
        DriverProfile::factory()->for($driver)->create(['is_available' => true]);
        WhatsAppSession::query()->create(['user_id' => $driver->id, 'opened_at' => now(), 'expires_at' => now()->addHours(1)]);

        WhatsAppFreeformSender::sendSessionExpiringSoonNotice($driver);

        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'messages' => [[
                            'from' => '593991234567',
                            'type' => 'interactive',
                            'interactive' => ['button_reply' => ['id' => 'wa_session_keepalive']],
                        ]],
                    ],
                ]],
            ]],
        ];

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();

        $this->assertTrue($driver->driverProfile->fresh()->is_available);
    }

    public function test_the_command_dispatches_the_job_for_a_driver_with_an_expiring_session(): void
    {
        Queue::fake();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        WhatsAppSession::query()->create(['user_id' => $driver->id, 'opened_at' => now()->subHours(23), 'expires_at' => now()->addHour()]);

        $this->artisan(NotifyExpiringWhatsAppSessions::class)->assertSuccessful();

        Queue::assertPushed(NotifyWhatsAppSessionExpiringSoon::class, fn ($job) => $job->driverUserId === $driver->id);
    }

    public function test_the_command_does_not_dispatch_twice_for_the_same_session(): void
    {
        Queue::fake();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        WhatsAppSession::query()->create([
            'user_id' => $driver->id,
            'opened_at' => now()->subHours(23),
            'expires_at' => now()->addHour(),
            'expiring_soon_notified_at' => now(),
        ]);

        $this->artisan(NotifyExpiringWhatsAppSessions::class)->assertSuccessful();

        Queue::assertNotPushed(NotifyWhatsAppSessionExpiringSoon::class);
    }

    public function test_the_command_ignores_a_session_that_is_not_the_current_one(): void
    {
        Queue::fake();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        // Sesión vieja, ya reemplazada por una apertura más nueva que todavía
        // no entra en el umbral de "por vencer" — no debería avisar por la vieja.
        WhatsAppSession::query()->create(['user_id' => $driver->id, 'opened_at' => now()->subDays(2), 'expires_at' => now()->addHour()]);
        WhatsAppSession::query()->create(['user_id' => $driver->id, 'opened_at' => now(), 'expires_at' => now()->addHours(23)]);

        $this->artisan(NotifyExpiringWhatsAppSessions::class)->assertSuccessful();

        Queue::assertNotPushed(NotifyWhatsAppSessionExpiringSoon::class);
    }

    public function test_the_command_ignores_a_client_without_a_driver_profile(): void
    {
        Queue::fake();
        $client = User::factory()->create();
        WhatsAppSession::query()->create(['user_id' => $client->id, 'opened_at' => now()->subHours(23), 'expires_at' => now()->addHour()]);

        $this->artisan(NotifyExpiringWhatsAppSessions::class)->assertSuccessful();

        Queue::assertNotPushed(NotifyWhatsAppSessionExpiringSoon::class);
    }
}
