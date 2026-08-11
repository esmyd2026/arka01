<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAuditLog;
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
