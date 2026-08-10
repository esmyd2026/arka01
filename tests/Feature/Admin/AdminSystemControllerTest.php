<?php

namespace Tests\Feature\Admin;

use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Zona de peligro" del panel admin (pedido explícito del usuario): borrar
 * toda la data de prueba (@arka01.test) y dejar el sistema reiniciado — ver
 * Admin\SystemController. Alcance confirmado con el usuario: solo cuentas
 *
 * @arka01.test, cualquier otro correo queda intacto.
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

    public function test_resetting_the_demo_deletes_arka01_test_accounts_and_recreates_the_base_cast(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'email' => 'admin@arka01.test']);
        User::factory()->create(['email' => 'cliente@arka01.test']);

        $this->actingAs($admin)->post(route('admin.system.reset-demo'))->assertRedirect();

        $this->assertSame(9, User::query()->where('email', 'like', '%@arka01.test')->count());
        $this->assertTrue(User::query()->where('email', 'admin@arka01.test')->exists());
        $this->assertTrue(User::query()->where('email', 'cliente@arka01.test')->exists());
        $this->assertTrue(User::query()->where('email', 'pedro@arka01.test')->exists());
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

    public function test_a_demo_admin_gets_logged_out_after_wiping_their_own_account(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'email' => 'admin@arka01.test']);

        $response = $this->actingAs($admin)->post(route('admin.system.reset-demo'));

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_non_demo_admin_stays_logged_in_after_the_reset(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'email' => 'admin@empresa-real.com']);

        $response = $this->actingAs($admin)->post(route('admin.system.reset-demo'));

        $response->assertRedirect();
        $this->assertAuthenticatedAs($admin->fresh());
    }
}
