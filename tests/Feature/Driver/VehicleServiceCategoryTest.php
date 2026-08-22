<?php

namespace Tests\Feature\Driver;

use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehicleServiceCategoryTest extends TestCase
{
    use RefreshDatabase;

    private function profilePayload(array $overrides = []): array
    {
        return array_merge([
            'license_number' => 'LIC-001',
            'vehicle_make' => 'Kia',
            'vehicle_model' => 'Soluto',
            'vehicle_color' => 'Blanco',
            'vehicle_type' => 'sedan',
            'vehicle_plate' => 'ABC-1234',
            'vehicle_year' => 2023,
            'passenger_capacity' => 4,
            'has_trunk' => true,
            'rate_per_km' => 0.50,
        ], $overrides);
    }

    public function test_a_driver_can_submit_optional_vehicle_amenities_for_admin_review(): void
    {
        $driver = User::factory()->create();
        $profile = DriverProfile::factory()->for($driver)->create([
            'service_category' => 'comfort',
            'vehicle_amenities' => [],
        ]);

        $this->actingAs($driver)->post(route('driver.profile.update'), $this->profilePayload([
            'vehicle_amenities' => ['smoke_free', 'air_conditioning', 'phone_charger'],
            // Aunque intente enviarla, la categoría no forma parte del
            // formulario validado del conductor.
            'service_category' => 'premium',
        ]))->assertSessionHasNoErrors();

        $profile->refresh();

        $this->assertSame(['air_conditioning', 'phone_charger', 'smoke_free'], $profile->vehicle_amenities);
        $this->assertNull($profile->service_category);
        $this->assertSame('pending', $profile->verification_status);
    }

    public function test_unknown_vehicle_amenities_are_rejected(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $this->actingAs($driver)->post(route('driver.profile.update'), $this->profilePayload([
            'vehicle_amenities' => ['flying_mode'],
        ]))->assertSessionHasErrors('vehicle_amenities.0');
    }

    public function test_an_admin_can_assign_a_reviewed_service_category(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $driver = User::factory()->create();
        $profile = DriverProfile::factory()->for($driver)->create([
            'service_category' => null,
            'vehicle_amenities' => ['air_conditioning', 'four_doors', 'spacious_interior'],
        ]);

        $this->actingAs($admin)->patch(route('admin.drivers.category', $profile), [
            'service_category' => 'comfort',
        ])->assertRedirect();

        $this->assertSame('comfort', $profile->fresh()->service_category);
        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'driver.service_category.update',
            'module' => 'drivers',
        ]);
    }

    public function test_an_admin_cannot_assign_an_unknown_service_category(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $profile = DriverProfile::factory()->for(User::factory())->create();

        $this->actingAs($admin)->patch(route('admin.drivers.category', $profile), [
            'service_category' => 'luxury_fake',
        ])->assertSessionHasErrors('service_category');
    }
}
