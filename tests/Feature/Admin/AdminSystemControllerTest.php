<?php

namespace Tests\Feature\Admin;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
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

        $this->assertSame(9, User::query()->where('email', 'like', '%@arka01.test')->count());
        $this->assertFalse(User::query()->whereKey($demoClient->id)->exists());
        $this->assertTrue(User::query()->where('email', 'cliente@arka01.test')->exists());
        $this->assertTrue(User::query()->where('email', 'pedro@arka01.test')->exists());
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
}
