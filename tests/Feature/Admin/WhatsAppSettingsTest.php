<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAuditLog;
use App\Models\ChatbotMessage;
use App\Models\User;
use App\Models\WhatsAppSetting;
use App\Services\WhatsAppConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Configuración → Integraciones → WhatsApp (roadmap de mejoras, sección 8):
 * editable desde el admin en vez de tocar el .env, con el .env como
 * respaldo — y sección 18: cada cambio queda auditado.
 */
class WhatsAppSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_regular_user_cannot_access_the_screen(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.integrations.whatsapp.edit'))->assertForbidden();
    }

    public function test_the_screen_never_exposes_the_real_secret_values(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        WhatsAppSetting::current()->update(['token' => 'super-secreto']);

        $response = $this->actingAs($admin)->get(route('admin.integrations.whatsapp.edit'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('settings.has_token', true)
            ->missing('settings.token')
        );
        $response->assertDontSee('super-secreto');
    }

    public function test_admin_can_update_the_non_sensitive_fields(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->patch(route('admin.integrations.whatsapp.update'), [
            'phone_number_id' => '999888777',
            'business_number' => '593991112222',
        ])->assertRedirect();

        $settings = WhatsAppSetting::current();
        $this->assertSame('999888777', $settings->phone_number_id);
        $this->assertSame('593991112222', $settings->business_number);
        $this->assertSame($admin->id, $settings->updated_by);
    }

    /**
     * Pedido explícito del usuario: la base de datos tiene prioridad sobre
     * el .env — ver App\Services\WhatsAppConfig.
     */
    public function test_a_token_saved_from_the_admin_takes_precedence_over_the_env(): void
    {
        Config::set('services.whatsapp.token', 'token-del-env');
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->patch(route('admin.integrations.whatsapp.update'), [
            'token' => 'token-de-la-base',
        ])->assertRedirect();

        $this->assertSame('token-de-la-base', WhatsAppConfig::token());
    }

    public function test_the_env_is_used_as_a_fallback_when_nothing_is_configured_in_the_admin(): void
    {
        Config::set('services.whatsapp.token', 'token-del-env');

        $this->assertSame('token-del-env', WhatsAppConfig::token());
    }

    /**
     * Pedido explícito del usuario: dejar un campo sensible en blanco no lo
     * borra — significa "no tocar lo que ya estaba guardado".
     */
    public function test_leaving_a_sensitive_field_blank_keeps_the_existing_value(): void
    {
        WhatsAppSetting::current()->update(['token' => 'token-original']);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->patch(route('admin.integrations.whatsapp.update'), [
            'token' => '',
            'business_number' => '593990000000',
        ])->assertRedirect();

        $this->assertSame('token-original', WhatsAppSetting::current()->token);
        $this->assertSame('593990000000', WhatsAppSetting::current()->business_number);
    }

    /**
     * Pedido explícito del usuario: "ayudame a configurar los modulos que
     * yo active de envios de whatsapp... Notificaciones: cliente: En
     * camino, aceptada...." — un renglón por tipo, con su estado.
     */
    public function test_the_screen_lists_every_notification_type_enabled_by_default(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->get(route('admin.integrations.whatsapp.edit'));

        $response->assertInertia(fn ($page) => $page
            ->has('notificationTypes', 10)
            ->where('notificationTypes.0.enabled', true)
        );
    }

    public function test_admin_can_disable_a_specific_notification_type(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->patch(route('admin.integrations.whatsapp.update'), [
            'notify_ride_started' => false,
        ])->assertRedirect();

        $this->assertFalse(WhatsAppSetting::current()->notify_ride_started);
        // El resto no se toca solo por apagar uno.
        $this->assertTrue(WhatsAppSetting::current()->notify_ride_accepted);
    }

    /**
     * Pedido explícito del usuario: "dame las cantidades de mensajes...
     * coloquemos precios estimados por las cantidades de mensajes enviados
     * quiero ver indicadores alli."
     */
    public function test_the_screen_reports_message_counts_and_estimated_cost(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        WhatsAppSetting::current()->update(['estimated_cost_per_message' => 0.0012]);

        ChatbotMessage::query()->create([
            'phone' => '+593991234567', 'direction' => 'out', 'body' => 'x',
            'meta' => ['successful' => true, 'type' => 'ride_accepted'],
        ]);
        ChatbotMessage::query()->create([
            'phone' => '+593991234567', 'direction' => 'out', 'body' => 'x',
            'meta' => ['successful' => true, 'type' => 'ride_accepted'],
        ]);
        // No cuenta: falló el envío.
        ChatbotMessage::query()->create([
            'phone' => '+593991234567', 'direction' => 'out', 'body' => 'x',
            'meta' => ['successful' => false, 'type' => 'ride_accepted'],
        ]);
        // No cuenta: es entrante, no saliente.
        ChatbotMessage::query()->create([
            'phone' => '+593991234567', 'direction' => 'in', 'body' => 'x', 'meta' => [],
        ]);

        $response = $this->actingAs($admin)->get(route('admin.integrations.whatsapp.edit'));

        $response->assertInertia(fn ($page) => $page
            ->where('messageStats.today', 2)
            ->where('messageStats.all_time', 2)
            ->where('messageStats.estimated_cost_all_time', 0.0024)
            ->where('notificationTypes.0.key', 'ride_accepted')
            ->where('notificationTypes.0.count_last_30_days', 2)
        );
    }

    public function test_updating_writes_an_audit_log_without_exposing_the_real_secret(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->patch(route('admin.integrations.whatsapp.update'), [
            'token' => 'token-nuevo',
            'business_number' => '593990000000',
        ])->assertRedirect();

        $log = AdminAuditLog::query()->latest('created_at')->firstOrFail();

        $this->assertSame($admin->id, $log->admin_user_id);
        $this->assertSame('integrations', $log->module);
        // El secreto nunca queda en el registro, solo si cambió.
        $this->assertSame('cambiado', $log->new_value['token']);
        $this->assertSame('593990000000', $log->new_value['business_number']);
    }
}
