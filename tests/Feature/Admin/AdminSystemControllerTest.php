<?php

namespace Tests\Feature\Admin;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\SiteSetting;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * "Zona de peligro" del panel admin (pedido explícito del usuario): borrar
 * los conductores y clientes de prueba (@arka01.test) — nunca ninguna cuenta
 * admin ni ninguna configuración ya hecha — y dejar el elenco demo listo de
 * nuevo. Ver Admin\SystemController.
 */
class AdminSystemControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_regular_user_cannot_access_the_system_panel(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.system.index'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.system.reset-demo'))->assertForbidden();
    }

    public function test_resetting_the_demo_deletes_non_admin_arka01_test_accounts_and_recreates_the_base_cast(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'email' => 'admin@arka01.test']);
        $demoClient = User::factory()->create(['email' => 'suelto@arka01.test']);

        $this->actingAs($admin)->post(route('admin.system.reset-demo'))->assertRedirect();

        // 9 de la base original (admin + 4 clientes + 4 conductores) + 14 del
        // elenco de flotas/cooperativas/públicos agregado después (pedido
        // explícito del usuario: "agrega flotas y cooperativas con
        // conductores de prueba... conductores públicos también" — ver
        // DemoDataSeeder).
        $this->assertSame(23, User::query()->where('email', 'like', '%@arka01.test')->count());
        $this->assertFalse(User::query()->whereKey($demoClient->id)->exists());
        $this->assertTrue(User::query()->where('email', 'cliente@arka01.test')->exists());
        $this->assertTrue(User::query()->where('email', 'pedro@arka01.test')->exists());
        $this->assertTrue(User::query()->where('email', 'diego.flota@arka01.test')->exists());
        $this->assertTrue(User::query()->where('email', 'coop.amazonas@arka01.test')->exists());
        $this->assertTrue(User::query()->where('email', 'nina.publica@arka01.test')->exists());
    }

    /**
     * Ajuste explícito del usuario a la versión anterior: antes esto SÍ
     * borraba (y volvía a crear) la cuenta admin de prueba — ahora ninguna
     * cuenta admin se toca nunca, sea cual sea su correo. Es la MISMA fila
     * (mismo id) después del reset, no una recreada.
     */
    public function test_an_admin_account_is_never_deleted_even_with_an_arka01_test_email(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'email' => 'admin@arka01.test']);

        $response = $this->actingAs($admin)->post(route('admin.system.reset-demo'));

        $response->assertRedirect();
        $this->assertAuthenticatedAs($admin->fresh());
        $this->assertTrue(User::query()->whereKey($admin->id)->where('email', 'admin@arka01.test')->exists());
    }

    public function test_reset_can_close_the_admin_session_and_open_the_demo_login(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'email' => 'admin@arka01.test']);

        $response = $this->actingAs($admin)->post(route('admin.system.reset-demo'), [
            'enter_demo' => true,
        ]);

        $response->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertTrue(User::query()->whereKey($admin->id)->exists());
    }

    public function test_a_second_admin_with_an_arka01_test_email_also_survives(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'email' => 'admin@arka01.test']);
        $secondAdmin = User::factory()->create(['is_admin' => true, 'email' => 'otro-admin@arka01.test']);

        $this->actingAs($admin)->post(route('admin.system.reset-demo'));

        $this->assertTrue(User::query()->whereKey($secondAdmin->id)->exists());
    }

    public function test_a_real_account_survives_the_reset_untouched(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'email' => 'admin@arka01.test']);
        $real = User::factory()->create(['email' => 'real.person@gmail.com']);

        $this->actingAs($admin)->post(route('admin.system.reset-demo'));

        $this->assertTrue(User::query()->whereKey($real->id)->where('email', 'real.person@gmail.com')->exists());
    }

    public function test_the_reset_cascades_to_a_demo_accounts_fleet(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'email' => 'admin@arka01.test']);
        $demoClient = User::factory()->create(['email' => 'flota@arka01.test']);
        $fleet = Fleet::factory()->for($demoClient, 'owner')->create();
        FleetMember::factory()->for($fleet)->create();

        $this->actingAs($admin)->post(route('admin.system.reset-demo'));

        $this->assertFalse(Fleet::query()->whereKey($fleet->id)->exists());
    }

    /**
     * Pedido explícito del usuario ("elimine... imágenes de esos usuarios"):
     * el cascade de la base borra las filas, pero nunca los archivos en
     * disco — SystemController tiene que borrarlos a mano antes.
     */
    public function test_the_reset_deletes_a_demo_drivers_photos_from_disk(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $admin = User::factory()->create(['is_admin' => true, 'email' => 'admin@arka01.test']);
        $demoDriver = User::factory()->create(['email' => 'foto@arka01.test']);

        $licensePath = UploadedFile::fake()->image('licencia.jpg')->store('driver-documents', 'local');
        $vehiclePath = UploadedFile::fake()->image('vehiculo.jpg')->store('driver-documents', 'public');
        DriverProfile::factory()->for($demoDriver)->create([
            'license_photo_path' => $licensePath,
            'vehicle_photo_path' => $vehiclePath,
        ]);

        $this->actingAs($admin)->post(route('admin.system.reset-demo'));

        Storage::disk('local')->assertMissing($licensePath);
        Storage::disk('public')->assertMissing($vehiclePath);
    }

    /**
     * Pedido explícito del usuario ("transacciones"): comprobantes de pago
     * subidos por un cliente/conductor de prueba también se limpian del disco.
     */
    public function test_the_reset_deletes_a_demo_users_payment_proof_from_disk(): void
    {
        Storage::fake('local');

        $admin = User::factory()->create(['is_admin' => true, 'email' => 'admin@arka01.test']);
        $demoClient = User::factory()->create(['email' => 'pago@arka01.test']);
        $plan = SubscriptionPlan::query()->where('owner_type', 'client')->firstOrFail();

        $proofPath = UploadedFile::fake()->image('comprobante.jpg')->store('payment-proofs', 'local');
        SubscriptionRequest::query()->create([
            'user_id' => $demoClient->id,
            'subscription_plan_id' => $plan->id,
            'payment_proof_path' => $proofPath,
            'status' => 'pending_review',
        ]);

        $this->actingAs($admin)->post(route('admin.system.reset-demo'));

        Storage::disk('local')->assertMissing($proofPath);
    }

    /**
     * Pedido explícito del usuario: las configuraciones ya hechas (acá,
     * el catálogo de planes) no se tocan — solo se leen para reportar el
     * comprobante, nunca se borran ni se recrean.
     */
    public function test_the_reset_does_not_touch_the_subscription_plan_catalog(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'email' => 'admin@arka01.test']);
        $plansBefore = SubscriptionPlan::query()->count();

        $this->actingAs($admin)->post(route('admin.system.reset-demo'));

        $this->assertSame($plansBefore, SubscriptionPlan::query()->count());
    }

    /**
     * Pedido explícito del usuario: "permiteme en el modulo de sistema de
     * habilitar o no estas opciones del menu tanto las del conductor como
     * las del cliente."
     */
    public function test_the_system_panel_lists_the_toggleable_quick_links(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->get(route('admin.system.index'));

        $response->assertInertia(fn ($page) => $page
            ->has('quickLinks', 16)
            ->where('quickLinks.0.enabled', true)
            ->where('quickLinks.0.route', 'driver.profile.edit')
            ->where('quickLinks.11.route', 'trust-circle.index')
            ->where('quickLinks.11.group', 'ambos')
            ->where('quickLinks.15.route', 'survey.show')
        );
    }

    public function test_a_regular_user_cannot_update_quick_links(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->patch(route('admin.system.quick-links.update'), [
            'disabled' => ['driver.plan.edit'],
        ])->assertForbidden();
    }

    public function test_admin_can_disable_a_quick_link(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->patch(route('admin.system.quick-links.update'), [
            'disabled' => ['driver.plan.edit', 'ride-requests.create'],
        ])->assertRedirect();

        $this->assertSame(
            ['driver.plan.edit', 'ride-requests.create'],
            SiteSetting::current()->disabled_quick_links
        );
    }

    public function test_re_enabling_everything_clears_the_list(): void
    {
        SiteSetting::current()->update(['disabled_quick_links' => ['driver.plan.edit']]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->patch(route('admin.system.quick-links.update'), [
            'disabled' => [],
        ])->assertRedirect();

        $this->assertSame([], SiteSetting::current()->disabled_quick_links);
    }

    /**
     * Solo las rutas del registro (App\Services\QuickLinkRegistry) se
     * pueden apagar — no cualquier string arbitrario mandado por el form.
     */
    public function test_an_unknown_route_is_rejected(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->patch(route('admin.system.quick-links.update'), [
            'disabled' => ['admin.system.reset-demo'],
        ])->assertSessionHasErrors('disabled.0');
    }

    /**
     * Pedido explícito del usuario: "permiteme desde el admin poder activar
     * o no lo obligatorio para que el conductor se le haga mas facil
     * activarse."
     */
    public function test_the_system_panel_lists_the_toggleable_driver_requirements(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->get(route('admin.system.index'));

        $response->assertInertia(fn ($page) => $page
            ->has('driverRequirements', 5)
            ->where('driverRequirements.0.enabled', true)
        );
    }

    public function test_a_regular_user_cannot_update_driver_requirements(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->patch(route('admin.system.driver-requirements.update'), [
            'disabled' => ['police_record'],
        ])->assertForbidden();
    }

    public function test_admin_can_disable_a_driver_requirement(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->patch(route('admin.system.driver-requirements.update'), [
            'disabled' => ['police_record', 'has_insurance'],
        ])->assertRedirect();

        $this->assertSame(
            ['police_record', 'has_insurance'],
            SiteSetting::current()->disabled_driver_requirements
        );
    }

    public function test_an_unknown_driver_requirement_key_is_rejected(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->patch(route('admin.system.driver-requirements.update'), [
            'disabled' => ['vehicle_plate'],
        ])->assertSessionHasErrors('disabled.0');
    }

    /**
     * Pedido explícito del usuario: "una lista de sonidos que pueda
     * seleccionar para las notificaciones y que las pueda activar desde el
     * panel administrativo. y que tenga todo el volumen."
     */
    public function test_the_system_panel_lists_the_notification_sound_categories_and_options(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->get(route('admin.system.index'));

        $response->assertInertia(fn ($page) => $page
            ->has('notificationSounds', 4)
            ->where('notificationSounds.0.sound', 'attention')
            ->has('notificationSoundOptions', 12)
            ->where('notificationSoundOptions.9.key', 'emergency_siren')
            ->where('notificationSoundOptions.10.key', 'repeating_alarm')
            ->where('notificationSoundOptions.11.key', 'dispatch_horn')
            ->where('notificationVolume', 100)
        );
    }

    public function test_a_regular_user_cannot_update_notification_sounds(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->patch(route('admin.system.notification-sounds.update'), [
            'sounds' => ['attention' => 'siren'],
            'volume' => 80,
        ])->assertForbidden();
    }

    public function test_admin_can_change_a_notification_sound_and_the_volume(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->patch(route('admin.system.notification-sounds.update'), [
            'sounds' => ['attention' => 'siren', 'incoming_ride' => 'marimba'],
            'volume' => 60,
        ])->assertRedirect();

        $siteSetting = SiteSetting::current();
        $this->assertSame(['attention' => 'siren', 'incoming_ride' => 'marimba'], $siteSetting->notification_sounds);
        $this->assertSame(60, $siteSetting->notification_volume);
    }

    public function test_an_unknown_sound_key_is_rejected(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->patch(route('admin.system.notification-sounds.update'), [
            'sounds' => ['attention' => 'not-a-real-sound'],
            'volume' => 80,
        ])->assertSessionHasErrors('sounds.attention');
    }

    public function test_the_volume_cannot_go_above_100_or_below_0(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->patch(route('admin.system.notification-sounds.update'), [
            'sounds' => [],
            'volume' => 150,
        ])->assertSessionHasErrors('volume');

        $this->actingAs($admin)->patch(route('admin.system.notification-sounds.update'), [
            'sounds' => [],
            'volume' => -5,
        ])->assertSessionHasErrors('volume');
    }

    /**
     * Solo las categorías reales del registro se guardan — cualquier otra
     * clave del payload (manipulado a mano) se descarta.
     */
    public function test_an_unknown_category_key_is_silently_dropped(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->patch(route('admin.system.notification-sounds.update'), [
            'sounds' => ['attention' => 'siren', 'not_a_real_category' => 'soft'],
            'volume' => 80,
        ])->assertRedirect();

        $this->assertSame(['attention' => 'siren'], SiteSetting::current()->notification_sounds);
    }
}
