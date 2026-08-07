<?php

namespace Tests\Feature\Security;

use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Verificación visible antes de subir (sección 8): un conductor sube foto de
 * licencia y vehículo, y un admin las aprueba o rechaza (sección 9.5-C).
 */
class DriverVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_uploading_documents_stores_them_and_resets_verification_to_pending(): void
    {
        // Disco privado para la licencia (documento de identidad), público
        // para la foto del vehículo (auditoría de seguridad — sí se muestra
        // en el directorio/perfil público, a propósito).
        Storage::fake('local');
        Storage::fake('public');

        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create([
            'verification_status' => 'approved',
        ]);

        $response = $this->actingAs($driver)->post(route('driver.profile.update'), [
            'license_number' => 'LIC-001',
            // Datos del vehículo, todos obligatorios (pedido explícito del usuario).
            'vehicle_make' => 'Chevrolet',
            'vehicle_model' => 'Spark',
            'vehicle_color' => 'Blanco',
            'vehicle_plate' => 'ABC-1234',
            'vehicle_year' => 2020,
            'passenger_capacity' => 4,
            'has_trunk' => true,
            'rate_per_km' => 0.5,
            'license_photo' => UploadedFile::fake()->image('licencia.jpg'),
            'vehicle_photo' => UploadedFile::fake()->image('vehiculo.jpg'),
        ]);

        $response->assertRedirect();

        $profile = $driver->driverProfile()->first();
        $this->assertSame('pending', $profile->verification_status);
        Storage::disk('local')->assertExists($profile->license_photo_path);
        Storage::disk('public')->assertExists($profile->vehicle_photo_path);
    }

    public function test_an_admin_can_approve_a_pending_verification(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $driver = User::factory()->create();
        $profile = DriverProfile::factory()->for($driver)->create([
            'verification_status' => 'pending',
            'license_photo_path' => 'driver-documents/example.jpg',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.driver-verifications.approve', $profile))
            ->assertRedirect();

        $this->assertSame('approved', $profile->fresh()->verification_status);
        $this->assertSame($admin->id, $profile->fresh()->verified_by);
    }

    public function test_an_admin_can_reject_a_pending_verification(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $driver = User::factory()->create();
        $profile = DriverProfile::factory()->for($driver)->create([
            'verification_status' => 'pending',
            'license_photo_path' => 'driver-documents/example.jpg',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.driver-verifications.reject', $profile), ['reason' => 'Foto de licencia borrosa.'])
            ->assertRedirect();

        $this->assertSame('rejected', $profile->fresh()->verification_status);
        $this->assertSame('Foto de licencia borrosa.', $profile->fresh()->verification_rejection_reason);
    }

    /**
     * Pedido explícito del usuario: si se rechaza, el admin tiene que dejar
     * asentado el motivo — para que el conductor sepa exactamente qué corregir.
     */
    public function test_rejecting_a_verification_requires_a_reason(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $driver = User::factory()->create();
        $profile = DriverProfile::factory()->for($driver)->create([
            'verification_status' => 'pending',
            'license_photo_path' => 'driver-documents/example.jpg',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.driver-verifications.reject', $profile))
            ->assertSessionHasErrors('reason');

        $this->assertSame('pending', $profile->fresh()->verification_status);
    }

    /**
     * Pedido explícito del usuario: mientras la documentación está "en
     * revisión", el conductor no puede subir fotos nuevas.
     */
    public function test_a_driver_cannot_reupload_documents_while_pending(): void
    {
        Storage::fake('local');

        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create([
            'verification_status' => 'pending',
            'license_photo_path' => 'driver-documents/example.jpg',
        ]);

        $this->actingAs($driver)->post(route('driver.profile.update'), [
            'license_number' => 'LIC-001',
            'vehicle_make' => 'Chevrolet',
            'vehicle_model' => 'Spark',
            'vehicle_color' => 'Blanco',
            'vehicle_plate' => 'ABC-1234',
            'vehicle_year' => 2020,
            'passenger_capacity' => 4,
            'has_trunk' => true,
            'rate_per_km' => 0.5,
            'license_photo' => UploadedFile::fake()->image('licencia-nueva.jpg'),
        ])->assertSessionHasErrors('license_photo');

        $this->assertSame('driver-documents/example.jpg', $driver->driverProfile->fresh()->license_photo_path);
    }

    /**
     * Simétrico: una vez rechazada, sí puede volver a subir — y eso limpia el
     * motivo del rechazo anterior (ya no aplica a la foto nueva).
     */
    public function test_a_driver_can_reupload_documents_after_being_rejected(): void
    {
        Storage::fake('local');

        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create([
            'verification_status' => 'rejected',
            'verification_rejection_reason' => 'Foto borrosa.',
            'license_photo_path' => 'driver-documents/example.jpg',
        ]);

        $this->actingAs($driver)->post(route('driver.profile.update'), [
            'license_number' => 'LIC-001',
            'vehicle_make' => 'Chevrolet',
            'vehicle_model' => 'Spark',
            'vehicle_color' => 'Blanco',
            'vehicle_plate' => 'ABC-1234',
            'vehicle_year' => 2020,
            'passenger_capacity' => 4,
            'has_trunk' => true,
            'rate_per_km' => 0.5,
            'license_photo' => UploadedFile::fake()->image('licencia-nueva.jpg'),
        ])->assertSessionHasNoErrors();

        $profile = $driver->driverProfile->fresh();
        $this->assertSame('pending', $profile->verification_status);
        $this->assertNull($profile->verification_rejection_reason);
    }

    public function test_a_regular_user_cannot_access_the_verification_queue(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.driver-verifications.index'))->assertForbidden();
    }

    /**
     * Auditoría de seguridad (pedido explícito del usuario): la foto de
     * licencia es un documento de identidad, va al disco privado — solo el
     * propio conductor o un admin pueden pedirla.
     */
    public function test_only_the_owner_or_an_admin_can_fetch_the_license_photo(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('driver-documents/licencia.jpg', 'contenido-falso');

        $driver = User::factory()->create(['is_admin' => false]);
        DriverProfile::factory()->for($driver)->create(['license_photo_path' => 'driver-documents/licencia.jpg']);

        $stranger = User::factory()->create(['is_admin' => false]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($stranger)
            ->get(route('driver-profile.license-photo', $driver))
            ->assertForbidden();

        $this->actingAs($driver)
            ->get(route('driver-profile.license-photo', $driver))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('driver-profile.license-photo', $driver))
            ->assertOk();
    }
}
