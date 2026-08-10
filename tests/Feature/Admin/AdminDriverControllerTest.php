<?php

namespace Tests\Feature\Admin;

use App\Events\DriverLocationUpdated;
use App\Models\City;
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

    // Paginado y filtros (pedido explícito del usuario) sobre "Todos los conductores".

    public function test_the_full_roster_is_paginated_at_twenty_per_page(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        collect(range(1, 25))->each(fn () => DriverProfile::factory()->for(User::factory())->create());

        $response = $this->actingAs($admin)->get(route('admin.drivers.index'));

        $response->assertInertia(fn ($page) => $page
            ->has('allDrivers.data', 20)
            ->where('allDrivers.total', 25)
            ->where('allDrivers.next_page_url', fn ($url) => ! is_null($url))
        );
    }

    public function test_the_roster_can_be_filtered_by_city(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $quito = City::query()->where('name', 'Quito')->firstOrFail();
        $guayaquil = City::query()->where('name', 'Guayaquil')->firstOrFail();

        $inQuito = User::factory()->create(['city_id' => $quito->id]);
        DriverProfile::factory()->for($inQuito)->create();

        $inGuayaquil = User::factory()->create(['city_id' => $guayaquil->id]);
        DriverProfile::factory()->for($inGuayaquil)->create();

        $response = $this->actingAs($admin)->get(route('admin.drivers.index', ['city_id' => $quito->id]));

        $response->assertInertia(fn ($page) => $page
            ->has('allDrivers.data', 1)
            ->where('allDrivers.data.0.user_id', $inQuito->id)
        );
    }

    public function test_the_roster_can_be_searched_by_name_or_email(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $match = User::factory()->create(['name' => 'Carlos Andrade']);
        DriverProfile::factory()->for($match)->create();

        $other = User::factory()->create(['name' => 'Pedro Salazar']);
        DriverProfile::factory()->for($other)->create();

        $response = $this->actingAs($admin)->get(route('admin.drivers.index', ['q' => 'Andrade']));

        $response->assertInertia(fn ($page) => $page
            ->has('allDrivers.data', 1)
            ->where('allDrivers.data.0.user_id', $match->id)
        );
    }
}
