<?php

namespace Tests\Feature\Api\V1;

use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Perfil/vehículo del conductor desde la app móvil (roadmap Hito 5, pasada
 * "full backend"). Reusa App\Services\Driver\DriverProfileUpdater, la misma
 * lógica que la web (tests\Feature\Ride\VehicleCapacityTest,
 * tests\Feature\Driver\DriverProfilePhoneUpdateTest,
 * tests\Feature\Security\DriverVerificationTest) — estos casos se enfocan
 * en el contrato JSON del canal móvil, no vuelven a probar cada regla de
 * negocio ya cubierta en esos archivos.
 */
class MobileDriverProfileTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function baseVehiclePayload(): array
    {
        return [
            'vehicle_make' => 'Chevrolet',
            'vehicle_model' => 'Spark',
            'vehicle_color' => 'Blanco',
            'vehicle_type' => 'sedan',
            'vehicle_plate' => 'ABC-1234',
            'vehicle_year' => 2020,
            'passenger_capacity' => 4,
            'has_trunk' => true,
            'rate_per_km' => 0.5,
            'has_insurance' => true,
        ];
    }

    public function test_without_a_profile_it_reports_null(): void
    {
        $driver = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->getJson('/api/v1/driver/profile')
            ->assertOk()
            ->assertJsonPath('profile', null);
    }

    public function test_creating_a_profile_for_the_first_time_requires_verification_documents(): void
    {
        $driver = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->postJson('/api/v1/driver/profile', $this->baseVehiclePayload())
            ->assertUnprocessable();

        $this->assertDatabaseMissing('driver_profiles', ['user_id' => $driver->id]);
    }

    public function test_creating_a_profile_with_all_documents_succeeds(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $driver = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->post('/api/v1/driver/profile', array_merge($this->baseVehiclePayload(), [
                'profile_photo' => UploadedFile::fake()->image('perfil.jpg'),
                'identity_document' => UploadedFile::fake()->image('cedula.jpg'),
                'license_photo' => UploadedFile::fake()->image('licencia.jpg'),
                'vehicle_registration' => UploadedFile::fake()->create('matricula.pdf', 100, 'application/pdf'),
            ]));

        $response->assertOk()->assertJsonPath('profile.verification_status', 'pending');
        $this->assertDatabaseHas('driver_profiles', [
            'user_id' => $driver->id,
            'vehicle_color' => 'Blanco',
            'passenger_capacity' => 4,
        ]);
    }

    public function test_an_existing_driver_can_update_editable_fields_without_reuploading_documents(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create([
            'rate_per_km' => 0.4,
            'max_request_distance_km' => null,
            'verification_status' => 'approved',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->postJson('/api/v1/driver/profile', array_merge($this->baseVehiclePayload(), [
                'rate_per_km' => 0.75,
                'max_request_distance_km' => 10,
                'accepts_cash' => true,
                'accepts_transfer' => false,
            ]));

        $response->assertOk()
            ->assertJsonPath('profile.rate_per_km', 0.75)
            ->assertJsonPath('profile.max_request_distance_km', 10)
            // Cambiar solo tarifa/cobertura no reabre la verificación ya aprobada.
            ->assertJsonPath('profile.verification_status', 'approved');
    }

    public function test_a_saved_vehicle_identity_field_cannot_be_changed_from_mobile(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['vehicle_plate' => 'ORIGINAL-1']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->postJson('/api/v1/driver/profile', array_merge($this->baseVehiclePayload(), [
                'vehicle_plate' => 'INTENTO-2',
            ]))
            ->assertOk()
            ->assertJsonPath('profile.vehicle_plate', 'ORIGINAL-1');
    }

    public function test_a_driver_can_update_their_declared_phone_number(): void
    {
        $driver = User::factory()->create(['phone' => '+593991111111']);
        DriverProfile::factory()->for($driver)->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->postJson('/api/v1/driver/profile', array_merge($this->baseVehiclePayload(), [
                'country_code' => '+593',
                'phone_local' => '992222222',
            ]))
            ->assertOk();

        $this->assertSame('+593992222222', $driver->fresh()->phone);
    }

    public function test_it_requires_a_token(): void
    {
        $this->getJson('/api/v1/driver/profile')->assertUnauthorized();
        $this->postJson('/api/v1/driver/profile', [])->assertUnauthorized();
    }
}
