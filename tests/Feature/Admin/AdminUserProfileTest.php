<?php

namespace Tests\Feature\Admin;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pedido explícito del usuario: "en el Administrador debe existir una opción
 * para consultar el perfil completo tanto del conductor como del cliente,
 * mostrando toda la información relevante sin necesidad de navegar por
 * diferentes pantallas".
 */
class AdminUserProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_regular_user_cannot_view_a_full_profile(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $other = User::factory()->create();

        $this->actingAs($user)->get(route('admin.users.show', $other))->assertForbidden();
    }

    public function test_an_admin_can_view_a_drivers_full_profile(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['verification_status' => 'approved']);

        $response = $this->actingAs($admin)->get(route('admin.users.show', $driver));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/UserProfile')
            ->where('profileUser.id', $driver->id)
            ->where('driverPlan.plan_code', 'gratis')
            ->where('clientPlan', null)
        );
    }

    public function test_an_admin_can_view_a_clients_full_profile(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = User::factory()->create();
        Fleet::factory()->for($client, 'owner')->create();

        $response = $this->actingAs($admin)->get(route('admin.users.show', $client));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/UserProfile')
            ->has('fleetsOwned', 1)
            ->where('driverPlan', null)
        );
    }
}
