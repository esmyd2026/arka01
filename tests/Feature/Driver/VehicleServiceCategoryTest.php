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
        // El resto de los campos "revisados" (placa, marca, capacidad, etc.)
        // tienen que coincidir EXACTO con lo que manda profilePayload() —
        // si no, la factory les pone valores al azar y cualquiera de esos
        // (no las comodidades) dispara igual el reseteo, enmascarando si el
        // fix de acá abajo funciona de verdad.
        $profile = DriverProfile::factory()->for($driver)->create(array_merge($this->profilePayload(), [
            'service_category' => 'comfort',
            'vehicle_amenities' => [],
            'verification_status' => 'approved',
        ]));

        $this->actingAs($driver)->post(route('driver.profile.update'), $this->profilePayload([
            'vehicle_amenities' => ['smoke_free', 'air_conditioning', 'phone_charger'],
            // Aunque intente enviarla, la categoría no forma parte del
            // formulario validado del conductor.
            'service_category' => 'premium',
        ]))->assertSessionHasNoErrors();

        $profile->refresh();

        $this->assertSame(['air_conditioning', 'phone_charger', 'smoke_free'], $profile->vehicle_amenities);
        // Bug real corregido (pedido explícito del usuario, caso real: un
        // conductor ya verificado tocó una comodidad opcional y quedó
        // forzado a esperar una nueva verificación): las comodidades son
        // datos opcionales — cambiarlas ya NO tira abajo una verificación
        // aprobada ni borra la categoría ya asignada, solo deja un aviso
        // informativo para administración.
        $this->assertSame('comfort', $profile->service_category);
        $this->assertSame('approved', $profile->verification_status);
        $this->assertDatabaseHas('system_events', [
            'event_type' => 'driver_amenities_updated',
            'module' => 'driver_profile',
            'severity' => 'info',
            'user_id' => $driver->id,
        ]);
    }

    public function test_a_saved_vehicle_identity_field_cannot_be_changed_by_the_driver(): void
    {
        $driver = User::factory()->create();
        $profile = DriverProfile::factory()->for($driver)->create(array_merge($this->profilePayload(), [
            'verification_status' => 'approved',
            'is_available' => true,
        ]));

        $this->actingAs($driver)->post(route('driver.profile.update'), $this->profilePayload([
            'vehicle_plate' => 'XYZ-9999',
        ]))->assertSessionHasNoErrors();

        $profile->refresh();

        $this->assertSame('ABC-1234', $profile->vehicle_plate);
        $this->assertSame('approved', $profile->verification_status);
        $this->assertTrue((bool) $profile->is_available);
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

    public function test_an_admin_can_assign_the_public_driver_category(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $profile = DriverProfile::factory()->for(User::factory())->create([
            'verification_status' => 'approved',
            'public_category' => null,
        ]);

        $this->actingAs($admin)->patch(route('admin.drivers.public-category', $profile), [
            'public_category' => 'professional',
        ])->assertRedirect();

        $this->assertSame('professional', $profile->fresh()->public_category);
        $this->assertSame('Conductor Profesional', $profile->fresh()->visiblePublicCategoryLabel());
        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'driver.public_category.update',
            'module' => 'drivers',
        ]);
    }

    public function test_an_admin_cannot_assign_an_unknown_public_driver_category(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $profile = DriverProfile::factory()->for(User::factory())->create();

        $this->actingAs($admin)->patch(route('admin.drivers.public-category', $profile), [
            'public_category' => 'informal',
        ])->assertSessionHasErrors('public_category');
    }

    public function test_a_driver_cannot_assign_their_own_public_category(): void
    {
        $driver = User::factory()->create();
        $profile = DriverProfile::factory()->for($driver)->create(array_merge($this->profilePayload(), [
            'public_category' => 'verified',
        ]));

        $this->actingAs($driver)->post(route('driver.profile.update'), $this->profilePayload([
            'public_category' => 'professional',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('verified', $profile->fresh()->public_category);
    }
}
