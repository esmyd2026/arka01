<?php

namespace Tests\Feature\Admin;

use App\Models\Coupon;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Centro de cupones y beneficios (pedido explícito del usuario): promociones
 * de comercios aliados, separadas por audiencia (cliente/conductor), cada
 * lado completamente independiente del otro.
 */
class CouponTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_regular_user_cannot_manage_coupons(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.coupons.index'))->assertForbidden();
    }

    public function test_an_admin_can_create_a_coupon_for_an_audience(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.coupons.store'), [
            'audience' => 'driver',
            'title' => 'Cambio de aceite gratis',
            'button_label' => 'Canjear',
            'button_url' => 'https://taller.example.com',
            'image' => UploadedFile::fake()->image('cupon.jpg'),
        ])->assertRedirect();

        $this->assertDatabaseHas('coupons', ['audience' => 'driver', 'title' => 'Cambio de aceite gratis']);
    }

    public function test_a_client_only_sees_client_coupons(): void
    {
        Coupon::query()->create(['audience' => 'client', 'image_path' => 'a.jpg', 'title' => 'Descuento supermercado', 'is_active' => true]);
        Coupon::query()->create(['audience' => 'driver', 'image_path' => 'b.jpg', 'title' => 'Cambio de aceite', 'is_active' => true]);

        $client = User::factory()->create();

        $response = $this->actingAs($client)->get(route('coupons.index'));

        $response->assertInertia(fn ($page) => $page
            ->component('Coupons/Index')
            ->where('audience', 'client')
            ->has('coupons', 1)
            ->where('coupons.0.title', 'Descuento supermercado')
        );
    }

    public function test_a_driver_only_sees_driver_coupons(): void
    {
        Coupon::query()->create(['audience' => 'client', 'image_path' => 'a.jpg', 'title' => 'Descuento supermercado', 'is_active' => true]);
        Coupon::query()->create(['audience' => 'driver', 'image_path' => 'b.jpg', 'title' => 'Cambio de aceite', 'is_active' => true]);

        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $response = $this->actingAs($driver)->get(route('coupons.index'));

        $response->assertInertia(fn ($page) => $page
            ->component('Coupons/Index')
            ->where('audience', 'driver')
            ->has('coupons', 1)
            ->where('coupons.0.title', 'Cambio de aceite')
        );
    }

    public function test_an_expired_coupon_is_not_shown(): void
    {
        Coupon::query()->create([
            'audience' => 'client', 'image_path' => 'a.jpg', 'title' => 'Vencido',
            'is_active' => true, 'expires_at' => now()->subDay(),
        ]);

        $client = User::factory()->create();

        $response = $this->actingAs($client)->get(route('coupons.index'));

        $response->assertInertia(fn ($page) => $page->has('coupons', 0));
    }
}
