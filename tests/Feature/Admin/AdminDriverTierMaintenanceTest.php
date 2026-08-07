<?php

namespace Tests\Feature\Admin;

use App\Models\DriverTier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pantalla de mantenimiento de medallas (pedido explícito del usuario): un
 * admin crea, edita o elimina medallas por completo desde /admin/medallas,
 * sin tocar código — calcado de AdminPlanMaintenanceTest.
 */
class AdminDriverTierMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_regular_user_cannot_access_the_driver_tiers_screen(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.driver-tiers.index'))->assertForbidden();
    }

    public function test_an_admin_can_create_a_new_tier(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.driver-tiers.store'), [
            'name' => 'Platino',
            'min_points' => 2000,
            'badge_emoji' => '🏆',
            'color_key' => 'purple',
            'is_public_eligible' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('driver_tiers', [
            'name' => 'Platino',
            'min_points' => 2000,
            'is_public_eligible' => true,
        ]);
    }

    public function test_min_points_must_be_unique(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.driver-tiers.store'), [
            'name' => 'Duplicada',
            'min_points' => 500, // ya usado por "Oro" en la semilla
            'color_key' => 'slate',
        ])->assertSessionHasErrors('min_points');
    }

    public function test_an_admin_can_edit_an_existing_tier(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $tier = DriverTier::query()->where('name', 'Plata')->firstOrFail();

        $this->actingAs($admin)->patch(route('admin.driver-tiers.update', $tier), [
            'name' => 'Plata',
            'min_points' => 200,
            'color_key' => 'slate',
            'is_public_eligible' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('driver_tiers', ['id' => $tier->id, 'min_points' => 200, 'is_public_eligible' => true]);
    }

    /**
     * La medalla de 0 puntos es el piso del sistema (ver
     * Admin\DriverTierController::destroy()) — sin ella, un conductor recién
     * empezado se quedaría sin ninguna medalla aplicable.
     */
    public function test_the_zero_point_tier_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $cobre = DriverTier::query()->where('min_points', 0)->firstOrFail();

        $this->actingAs($admin)
            ->delete(route('admin.driver-tiers.destroy', $cobre))
            ->assertSessionHasErrors('driver_tier');

        $this->assertDatabaseHas('driver_tiers', ['id' => $cobre->id]);
    }

    public function test_a_non_zero_tier_can_be_deleted(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $plata = DriverTier::query()->where('name', 'Plata')->firstOrFail();

        $this->actingAs($admin)->delete(route('admin.driver-tiers.destroy', $plata))->assertRedirect();

        $this->assertDatabaseMissing('driver_tiers', ['id' => $plata->id]);
    }
}
