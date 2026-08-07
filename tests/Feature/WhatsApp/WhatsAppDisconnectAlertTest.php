<?php

namespace Tests\Feature\WhatsApp;

use App\Console\Commands\SweepStaleDriverAvailability;
use App\Jobs\NotifyDriverDisconnectedByWhatsApp;
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
 * Pedido explícito del usuario: avisar por WhatsApp a un conductor que se
 * desconecta — a propósito (toggle "Activarme"/logout) o involuntariamente
 * (el barrido de conductores stale) — para animarlo a volver, siempre y
 * cuando siga dentro de la ventana de 24h. El envío real va encolado
 * (App\Jobs\NotifyDriverDisconnectedByWhatsApp), nunca sincrónico con la
 * acción que lo dispara.
 */
class WhatsAppDisconnectAlertTest extends TestCase
{
    use RefreshDatabase;

    private function enableWhatsApp(): void
    {
        Config::set('services.whatsapp.token', 'fake-token');
        Config::set('services.whatsapp.phone_number_id', '123456');
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.fake']]], 200)]);
    }

    public function test_turning_off_availability_dispatches_the_disconnect_job(): void
    {
        Queue::fake();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['is_available' => true]);

        $this->actingAs($driver)->post(route('driver.location.update'), [
            'lat' => -0.18, 'lng' => -78.46, 'is_available' => false,
        ])->assertOk();

        Queue::assertPushed(NotifyDriverDisconnectedByWhatsApp::class, fn ($job) => $job->driverUserId === $driver->id);
    }

    public function test_turning_on_availability_does_not_dispatch_the_disconnect_job(): void
    {
        Queue::fake();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['is_available' => false]);

        $this->actingAs($driver)->post(route('driver.location.update'), [
            'lat' => -0.18, 'lng' => -78.46, 'is_available' => true,
        ])->assertOk();

        Queue::assertNotPushed(NotifyDriverDisconnectedByWhatsApp::class);
    }

    public function test_a_routine_ping_while_already_available_does_not_dispatch_the_disconnect_job(): void
    {
        Queue::fake();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['is_available' => true]);

        $this->actingAs($driver)->post(route('driver.location.update'), [
            'lat' => -0.18, 'lng' => -78.46, 'is_available' => true,
        ])->assertOk();

        Queue::assertNotPushed(NotifyDriverDisconnectedByWhatsApp::class);
    }

    public function test_logging_out_while_available_dispatches_the_disconnect_job(): void
    {
        Queue::fake();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['is_available' => true]);

        $this->actingAs($driver)->post(route('logout'))->assertRedirect('/');

        Queue::assertPushed(NotifyDriverDisconnectedByWhatsApp::class, fn ($job) => $job->driverUserId === $driver->id);
    }

    public function test_logging_out_while_already_unavailable_does_not_dispatch(): void
    {
        Queue::fake();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['is_available' => false]);

        $this->actingAs($driver)->post(route('logout'))->assertRedirect('/');

        Queue::assertNotPushed(NotifyDriverDisconnectedByWhatsApp::class);
    }

    public function test_the_stale_sweep_dispatches_the_disconnect_job_for_each_stale_driver(): void
    {
        Queue::fake();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create([
            'is_available' => true,
            'location_updated_at' => now()->subMinutes(5),
        ]);

        $this->artisan(SweepStaleDriverAvailability::class)->assertSuccessful();

        Queue::assertPushed(NotifyDriverDisconnectedByWhatsApp::class, fn ($job) => $job->driverUserId === $driver->id);
    }

    public function test_the_job_sends_a_whatsapp_message_when_the_driver_has_an_active_session(): void
    {
        $this->enableWhatsApp();
        $driver = User::factory()->create(['phone' => '+593991234567']);
        WhatsAppSession::query()->create(['user_id' => $driver->id, 'opened_at' => now(), 'expires_at' => now()->addHours(20)]);

        WhatsAppFreeformSender::sendDisconnectedAlert($driver);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com')
            && $request['type'] === 'text'
            && $request['to'] === '593991234567');
    }

    public function test_the_job_does_not_send_a_whatsapp_message_without_an_active_session(): void
    {
        $this->enableWhatsApp();
        $driver = User::factory()->create(['phone' => '+593991234567']);

        WhatsAppFreeformSender::sendDisconnectedAlert($driver);

        Http::assertNothingSent();
    }
}
