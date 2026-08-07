<?php

namespace Tests\Feature\Admin;

use App\Events\DriverLocationUpdated;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Panel admin de conductores (pedido explícito del usuario): ver quién está
 * disponible ahora con su ubicación (y que desaparezca al no estarlo), y
 * poder bloquear/deshabilitar/desconectar — unificado en DriverProfile::isSuspended().
 */
class AdminDriverControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_regular_user_cannot_access_the_drivers_panel(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.drivers.index'))->assertForbidden();
    }

    public function test_only_available_drivers_with_known_location_show_on_the_live_list(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $online = User::factory()->create();
        DriverProfile::factory()->for($online)->create(['is_available' => true, 'current_lat' => -0.18, 'current_lng' => -78.46]);

        $offline = User::factory()->create();
        DriverProfile::factory()->for($offline)->create(['is_available' => false]);

        $noLocation = User::factory()->create();
        DriverProfile::factory()->for($noLocation)->create(['is_available' => true, 'current_lat' => null, 'current_lng' => null]);

        $response = $this->actingAs($admin)->get(route('admin.drivers.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('availableDrivers.0.user_id', $online->id)
            ->has('availableDrivers', 1)
        );
    }

    public function test_an_admin_can_suspend_a_driver_and_it_disconnects_them(): void
    {
        Event::fake([DriverLocationUpdated::class]);

        $admin = User::factory()->create(['is_admin' => true]);
        $driver = User::factory()->create();
        $profile = DriverProfile::factory()->for($driver)->create(['is_available' => true]);

        $this->actingAs($admin)->post(route('admin.drivers.suspend', $profile))->assertRedirect();

        $profile->refresh();
        $this->assertTrue($profile->isSuspended());
        $this->assertFalse($profile->is_available);
        Event::assertDispatched(DriverLocationUpdated::class);
    }

    public function test_a_suspended_driver_cannot_reconnect_themselves(): void
    {
        $driver = User::factory()->create();
        $profile = DriverProfile::factory()->for($driver)->create(['is_available' => false]);
        $profile->forceFill(['suspended_at' => now()])->save();

        $this->actingAs($driver)->post(route('driver.location.update'), [
            'lat' => -0.18,
            'lng' => -78.46,
            'is_available' => true,
        ])->assertForbidden();

        $this->assertFalse($profile->fresh()->is_available);
    }

    public function test_an_admin_can_reactivate_a_suspended_driver(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $driver = User::factory()->create();
        $profile = DriverProfile::factory()->for($driver)->create();
        $profile->forceFill(['suspended_at' => now()])->save();

        $this->actingAs($admin)->post(route('admin.drivers.reactivate', $profile))->assertRedirect();

        $this->assertFalse($profile->fresh()->isSuspended());
    }
}
