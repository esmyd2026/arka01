<?php

namespace Tests\Feature\Admin;

use App\Models\City;
use App\Models\RideRequest;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pantalla de mantenimiento de "zonas del Ecuador" (ciudades y sectores,
 * consideración agregada al alcance): un admin arma el catálogo del que sale
 * la lista que el cliente usa al pedir una carrera — nada de esto puede
 * quedar quemado en código.
 */
class LocationMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_regular_user_cannot_access_the_locations_maintenance_screen(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.locations.index'))->assertForbidden();
    }

    public function test_an_admin_can_create_a_city(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.cities.store'), [
            'name' => 'Manta',
            'province' => 'Manabí',
        ])->assertRedirect();

        $this->assertDatabaseHas('cities', ['name' => 'Manta', 'province' => 'Manabí']);
    }

    public function test_an_admin_can_add_a_sector_to_a_city(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $city = City::query()->where('name', 'Guayaquil')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.sectors.store', $city), [
            'name' => 'Puerto Santa Ana',
        ])->assertRedirect();

        $this->assertDatabaseHas('sectors', ['city_id' => $city->id, 'name' => 'Puerto Santa Ana']);
    }

    public function test_a_city_that_still_has_sectors_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $city = City::query()->where('name', 'Guayaquil')->firstOrFail();

        $this->actingAs($admin)
            ->delete(route('admin.cities.destroy', $city))
            ->assertSessionHasErrors('city');

        $this->assertDatabaseHas('cities', ['id' => $city->id]);
    }

    public function test_an_empty_city_can_be_deleted(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $city = City::query()->create(['name' => 'Ciudad Vacía', 'is_active' => true]);

        $this->actingAs($admin)->delete(route('admin.cities.destroy', $city))->assertRedirect();

        $this->assertDatabaseMissing('cities', ['id' => $city->id]);
    }

    public function test_deleting_a_sector_does_not_break_ride_requests_that_already_used_it(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $sector = Sector::query()->where('name', 'Sauces 1')->firstOrFail();
        $rideRequest = RideRequest::factory()->create(['origin_sector_id' => $sector->id]);

        $this->actingAs($admin)->delete(route('admin.sectors.destroy', $sector))->assertRedirect();

        $this->assertDatabaseMissing('sectors', ['id' => $sector->id]);
        // nullOnDelete (sección de la migración): la solicitud sigue existiendo,
        // solo pierde la referencia al sector borrado.
        $this->assertNull($rideRequest->fresh()->origin_sector_id);
    }
}
