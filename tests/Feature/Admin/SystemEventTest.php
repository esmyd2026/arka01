<?php

namespace Tests\Feature\Admin;

use App\Models\SystemEvent;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Services\WhatsAppFreeformSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Administración → Monitoreo (roadmap de mejoras, sección 9): errores
 * críticos visibles sin entrar a storage/logs.
 */
class SystemEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_regular_user_cannot_access_the_screen(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.monitoring.index'))->assertForbidden();
    }

    public function test_events_can_be_filtered_by_module_and_severity(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        SystemEvent::factory()->create(['module' => 'whatsapp', 'severity' => 'error', 'message' => 'Falló A']);
        SystemEvent::factory()->create(['module' => 'sos', 'severity' => 'warning', 'message' => 'Falló B']);

        $response = $this->actingAs($admin)->get(route('admin.monitoring.index', ['module' => 'whatsapp']));

        $response->assertInertia(fn ($page) => $page
            ->has('events.data', 1)
            ->where('events.data.0.message', 'Falló A')
        );

        $response = $this->actingAs($admin)->get(route('admin.monitoring.index', ['severity' => 'warning']));

        $response->assertInertia(fn ($page) => $page
            ->has('events.data', 1)
            ->where('events.data.0.message', 'Falló B')
        );
    }

    public function test_marking_an_event_resolved_updates_its_status(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $event = SystemEvent::factory()->create(['status' => 'failed']);

        $this->actingAs($admin)
            ->post(route('admin.monitoring.resolve', $event))
            ->assertRedirect();

        $this->assertSame('resolved', $event->fresh()->status);
    }

    /**
     * Pedido explícito del usuario, ejemplo textual del roadmap: "si un
     * WhatsApp no fue enviado, quiero saber" — confirma que el hook real
     * (no solo el modelo/factory) deja el registro.
     */
    public function test_a_failed_whatsapp_send_is_recorded_as_a_system_event(): void
    {
        Config::set('services.whatsapp.token', 'fake-token');
        Config::set('services.whatsapp.phone_number_id', '123456');
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid token']], 401)]);

        $driver = User::factory()->create(['phone' => '+593991234567']);
        WhatsAppSession::query()->create(['user_id' => $driver->id, 'opened_at' => now(), 'expires_at' => now()->addHours(20)]);

        WhatsAppFreeformSender::sendText($driver->phone, 'Hola');

        $this->assertDatabaseHas('system_events', [
            'module' => 'whatsapp',
            'event_type' => 'whatsapp_send_failed',
            'severity' => 'error',
        ]);
    }
}
