<?php

namespace Tests\Feature\Api\V1;

use App\Models\Coupon;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Centro de cupones y beneficios desde la app móvil (roadmap Hito 5B,
 * paridad con la web) — mismo filtro que CouponController (web).
 */
class MobileCouponTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_a_client_sees_only_client_coupons(): void
    {
        $client = User::factory()->create();
        Coupon::query()->create(['audience' => 'client', 'title' => 'Para clientes', 'image_path' => 'coupons/x.jpg', 'is_active' => true]);
        Coupon::query()->create(['audience' => 'driver', 'title' => 'Para conductores', 'image_path' => 'coupons/x.jpg', 'is_active' => true]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->getJson('/api/v1/coupons');

        $response->assertOk()
            ->assertJsonPath('audience', 'client')
            ->assertJsonCount(1, 'coupons')
            ->assertJsonPath('coupons.0.title', 'Para clientes');
    }

    public function test_inactive_coupons_are_hidden(): void
    {
        $client = User::factory()->create();
        Coupon::query()->create(['audience' => 'client', 'title' => 'Inactivo', 'image_path' => 'coupons/x.jpg', 'is_active' => false]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->getJson('/api/v1/coupons')
            ->assertOk()
            ->assertJsonCount(0, 'coupons');
    }

    public function test_a_driver_sees_driver_coupons(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        Coupon::query()->create(['audience' => 'driver', 'title' => 'Para conductores', 'image_path' => 'coupons/x.jpg', 'is_active' => true]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->getJson('/api/v1/coupons')
            ->assertOk()
            ->assertJsonPath('audience', 'driver')
            ->assertJsonCount(1, 'coupons');
    }

    public function test_it_requires_a_token(): void
    {
        $this->getJson('/api/v1/coupons')->assertUnauthorized();
    }
}
