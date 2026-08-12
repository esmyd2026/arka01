<?php

namespace Tests\Feature\Admin;

use App\Models\City;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pedido explícito del usuario: "ver de dónde se registran las personas,
 * por su ubicación" — agrupa a los usuarios por ciudad para el panel admin.
 */
class UserLocationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_regular_user_cannot_access_the_screen(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.user-locations.index'))->assertForbidden();
    }

    public function test_an_admin_sees_users_grouped_by_city(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $quito = City::query()->create(['name' => 'Quito', 'province' => 'Pichincha', 'lat' => -0.1807, 'lng' => -78.4678, 'is_active' => true]);
        $guayaquil = City::query()->create(['name' => 'Guayaquil', 'province' => 'Guayas', 'lat' => -2.1894, 'lng' => -79.8891, 'is_active' => true]);

        // Cantidades bien distintas a propósito (3, 2, 1): sin empate entre
        // grupos, el orden descendente queda determinístico.
        User::factory()->count(3)->create(['city_id' => $quito->id]);
        User::factory()->count(2)->create(['city_id' => $guayaquil->id]);
        User::factory()->create(['city_id' => null]);

        $response = $this->actingAs($admin)->get(route('admin.user-locations.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('byCity.0.city', 'Quito')
            ->where('byCity.0.total', 3)
            ->where('byCity.1.city', 'Guayaquil')
            ->where('byCity.1.total', 2)
            ->where('byCity.2.city', 'Sin ciudad')
            ->where('byCity.2.total', 1)
        );
    }

    public function test_admin_accounts_are_excluded_from_the_counts(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $quito = City::query()->create(['name' => 'Quito', 'lat' => -0.1807, 'lng' => -78.4678, 'is_active' => true]);

        User::factory()->create(['city_id' => $quito->id, 'is_admin' => true]);

        $response = $this->actingAs($admin)->get(route('admin.user-locations.index'));

        $response->assertInertia(fn ($page) => $page->where('totalUsers', 0));
    }
}
