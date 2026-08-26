<?php

namespace Tests\Feature\Admin;

use App\Models\City;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Módulo de clientes registrados (pedido explícito del usuario): mismo
 * criterio que el panel de conductores, del otro lado — ver Admin\ClientController.
 */
class AdminClientControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_regular_user_cannot_access_the_clients_panel(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.clients.index'))->assertForbidden();
    }

    public function test_the_list_only_includes_clients_not_drivers_or_admins(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = User::factory()->create();

        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $response = $this->actingAs($admin)->get(route('admin.clients.index'));

        $response->assertInertia(fn ($page) => $page
            ->has('clients.data', 1)
            ->where('clients.data.0.id', $client->id)
        );
    }

    public function test_the_list_can_be_filtered_by_city_and_search(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $quito = City::query()->where('name', 'Quito')->firstOrFail();
        $guayaquil = City::query()->where('name', 'Guayaquil')->firstOrFail();

        $match = User::factory()->create(['name' => 'Carlos Andrade', 'city_id' => $quito->id]);
        User::factory()->create(['name' => 'Pedro Salazar', 'city_id' => $guayaquil->id]);

        $response = $this->actingAs($admin)->get(route('admin.clients.index', ['q' => 'Andrade']));

        $response->assertInertia(fn ($page) => $page
            ->has('clients.data', 1)
            ->where('clients.data.0.id', $match->id)
        );

        $response = $this->actingAs($admin)->get(route('admin.clients.index', ['city_id' => $quito->id]));

        $response->assertInertia(fn ($page) => $page
            ->has('clients.data', 1)
            ->where('clients.data.0.id', $match->id)
        );
    }

    public function test_the_list_is_paginated_at_twenty_per_page(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        User::factory()->count(25)->create();

        $response = $this->actingAs($admin)->get(route('admin.clients.index'));

        $response->assertInertia(fn ($page) => $page
            ->has('clients.data', 20)
            ->where('clients.total', 25)
        );
    }

    public function test_it_shows_how_many_drivers_are_in_the_clients_fleet(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();

        // "added_by" explícito en las tres (pedido implícito por la propia
        // factory: sin esto, FleetMemberFactory arma un User::factory() nuevo
        // para "added_by" en cada llamada — tres cuentas fantasma de sobra,
        // con role 'cliente' por defecto, que le ganaban a $client en el
        // orden alfabético de la lista).
        $driverOne = User::factory()->create();
        DriverProfile::factory()->for($driverOne)->create();
        FleetMember::factory()->for($fleet)->for($driverOne, 'driver')->create(['added_by' => $client->id]);

        $driverTwo = User::factory()->create();
        DriverProfile::factory()->for($driverTwo)->create();
        FleetMember::factory()->for($fleet)->for($driverTwo, 'driver')->create(['added_by' => $client->id]);

        // Un miembro que ya se fue no cuenta.
        $formerDriver = User::factory()->create();
        DriverProfile::factory()->for($formerDriver)->create();
        FleetMember::factory()->for($fleet)->for($formerDriver, 'driver')->create(['added_by' => $client->id, 'left_at' => now()]);

        $response = $this->actingAs($admin)->get(route('admin.clients.index'));

        $response->assertInertia(fn ($page) => $page
            ->has('clients.data', 1)
            ->where('clients.data.0.id', $client->id)
            ->where('clients.data.0.drivers_count', 2)
        );
    }
}
