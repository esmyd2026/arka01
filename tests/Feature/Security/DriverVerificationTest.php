<?php

namespace Tests\Feature\Security;

use App\Models\DriverProfile;
use App\Models\User;
use App\Notifications\DriverVerificationResultPushNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Verificación documental: cédula, licencia, antecedentes y foto de perfil.
 */
class DriverVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_uploading_documents_stores_them_and_resets_verification_to_pending(): void
    {
        // Todos los documentos viven en privado; solo el avatar es público.
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
            'vehicle_type' => 'sedan',
            'vehicle_plate' => 'ABC-1234',
            'vehicle_year' => 2020,
            'passenger_capacity' => 4,
            'has_trunk' => true,
            'rate_per_km' => 0.5,
            'profile_photo' => UploadedFile::fake()->image('perfil.jpg'),
            'identity_document' => UploadedFile::fake()->image('cedula.jpg'),
            'license_photo' => UploadedFile::fake()->image('licencia.jpg'),
            'police_record' => UploadedFile::fake()->create('antecedentes.pdf', 100, 'application/pdf'),
        ]);

        $response->assertRedirect();

        $profile = $driver->driverProfile()->first();
        $this->assertSame('pending', $profile->verification_status);
        Storage::disk('local')->assertExists($profile->identity_document_path);
        Storage::disk('local')->assertExists($profile->license_photo_path);
        Storage::disk('local')->assertExists($profile->police_record_path);
        Storage::disk('public')->assertExists($driver->fresh()->avatar_path);
    }

    /**
     * Bug crítico reportado por el usuario: un conductor guardaba su perfil
     * la primera vez SIN subir ninguna foto, y terminaba con
     * verification_status = 'pending' igual (antes era el default de la
     * columna ENUM) — quedaba bloqueado para subir documentos sin haber
     * subido ninguno todavía, y no aparecía en la cola de revisión del
     * admin (que exige license_photo_path no nulo) porque no había nada que
     * revisar. Ahora el estado sin documentos es null, no 'pending'.
     */
    public function test_saving_the_profile_for_the_first_time_requires_verification_documents(): void
    {
        $driver = User::factory()->create();

        $this->actingAs($driver)->post(route('driver.profile.update'), [
            'license_number' => 'LIC-001',
            'vehicle_make' => 'Chevrolet',
            'vehicle_model' => 'Spark',
            'vehicle_color' => 'Blanco',
            'vehicle_type' => 'sedan',
            'vehicle_plate' => 'ABC-1234',
            'vehicle_year' => 2020,
            'passenger_capacity' => 4,
            'has_trunk' => true,
            'rate_per_km' => 0.5,
        ])->assertSessionHasErrors('identity_document');

        $this->assertNull($driver->driverProfile()->first());
    }

    public function test_an_admin_can_approve_a_pending_verification(): void
    {
        Notification::fake();
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
        Notification::assertSentTo($driver, DriverVerificationResultPushNotification::class);
    }

    public function test_an_admin_cannot_approve_an_incomplete_driver_profile(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $driver = User::factory()->create();
        $profile = DriverProfile::factory()->for($driver)->create([
            'verification_status' => 'pending',
            'vehicle_plate' => null,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.driver-verifications.approve', $profile))
            ->assertSessionHasErrors('verification');

        $this->assertSame('pending', $profile->fresh()->verification_status);
    }

    public function test_an_admin_can_reject_a_pending_verification(): void
    {
        Notification::fake();
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
        Notification::assertSentTo($driver, DriverVerificationResultPushNotification::class);
    }

    public function test_rejecting_verification_disconnects_the_driver(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        $driver = User::factory()->create();
        $profile = DriverProfile::factory()->for($driver)->create([
            'verification_status' => 'pending',
            'is_available' => true,
        ]);

        $this->actingAs($admin)->post(
            route('admin.driver-verifications.reject', $profile),
            ['reason' => 'La información no coincide.']
        )->assertRedirect();

        $this->assertFalse($profile->fresh()->is_available);
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
            'identity_document_path' => 'driver-documents/cedula.jpg',
            'license_photo_path' => 'driver-documents/example.jpg',
            'police_record_path' => 'driver-documents/record.pdf',
        ]);

        $this->actingAs($driver)->post(route('driver.profile.update'), [
            'license_number' => 'LIC-001',
            'vehicle_make' => 'Chevrolet',
            'vehicle_model' => 'Spark',
            'vehicle_color' => 'Blanco',
            'vehicle_type' => 'sedan',
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
        $driver->forceFill(['avatar_path' => 'https://example.com/avatar.jpg'])->save();
        DriverProfile::factory()->for($driver)->create([
            'verification_status' => 'rejected',
            'verification_rejection_reason' => 'Foto borrosa.',
            'identity_document_path' => 'driver-documents/cedula.jpg',
            'license_photo_path' => 'driver-documents/example.jpg',
            'police_record_path' => 'driver-documents/record.pdf',
        ]);

        $this->actingAs($driver)->post(route('driver.profile.update'), [
            'license_number' => 'LIC-001',
            'vehicle_make' => 'Chevrolet',
            'vehicle_model' => 'Spark',
            'vehicle_color' => 'Blanco',
            'vehicle_type' => 'sedan',
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

        $adminResponse = $this->actingAs($admin)
            ->get(route('driver-profile.license-photo', $driver))
            ->assertOk();

        // El documento sigue siendo privado, pero el panel administrativo
        // puede incrustarlo desde el mismo dominio para revisarlo.
        $adminResponse->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $this->assertStringContainsString(
            "frame-ancestors 'self'",
            (string) $adminResponse->headers->get('Content-Security-Policy'),
        );
    }
}
