<?php

namespace Tests\Feature\Security;

use App\Models\DriverProfile;
use App\Models\SiteSetting;
use App\Models\User;
use App\Notifications\AdminDriverLifecyclePushNotification;
use App\Notifications\DriverVerificationResultPushNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\TestCase;

/**
 * Verificación documental: cédula, licencia, antecedentes y foto de perfil.
 */
class DriverVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_verification_page_orders_drivers_by_activation_stage(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        User::factory()->create(['intends_to_drive' => true]);

        DriverProfile::factory()->for(User::factory())->create([
            'verification_status' => null,
            'vehicle_make' => null,
        ]);
        DriverProfile::factory()->for(User::factory())->create(['verification_status' => 'pending']);
        DriverProfile::factory()->for(User::factory())->create([
            'verification_status' => 'rejected',
            'verification_rejection_reason' => 'Documento borroso.',
        ]);
        DriverProfile::factory()->for(User::factory())->create([
            'verification_status' => 'approved',
            'verified_at' => now(),
            'public_category' => 'verified',
        ]);

        $this->actingAs($admin)->get(route('admin.driver-verifications.index'))
            ->assertInertia(fn ($page) => $page
                ->component('Admin/DriverVerifications')
                ->has('registered', 1)
                ->has('incomplete', 1)
                ->has('pending', 1)
                ->has('rejected', 1)
                ->has('approved', 1)
                ->where('publicDriverCategories.professional.label', 'Conductor Profesional')
                ->where('serviceCategories.comfort.label', 'Confort')
                ->where('vehicleAmenities.air_conditioning.label', 'Aire acondicionado'));
    }

    public function test_uploading_documents_stores_them_and_resets_verification_to_pending(): void
    {
        Notification::fake();
        // Todos los documentos viven en privado; solo el avatar es público.
        Storage::fake('local');
        Storage::fake('public');

        $driver = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
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
        Notification::assertSentTo($admin, AdminDriverLifecyclePushNotification::class, fn ($notification) => $notification->stage === 'ready');
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

    /**
     * Pedido explícito del usuario: seguro que lo proteja a él, a los
     * pasajeros y al vehículo — autodeclarado con un checkbox, sin
     * documento, pero igual obligatorio para solicitar verificación (mismo
     * criterio que cédula/licencia/antecedentes).
     */
    public function test_solicitar_verificacion_sin_declarar_seguro_es_rechazado(): void
    {
        Storage::fake('local');
        Storage::fake('public');
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
            'profile_photo' => UploadedFile::fake()->image('perfil.jpg'),
            'identity_document' => UploadedFile::fake()->image('cedula.jpg'),
            'license_photo' => UploadedFile::fake()->image('licencia.jpg'),
            'police_record' => UploadedFile::fake()->create('antecedentes.pdf', 100, 'application/pdf'),
            // has_insurance deliberadamente ausente.
        ])->assertSessionHasErrors('has_insurance');

        $this->assertNull($driver->driverProfile()->first());
    }

    /**
     * Pedido explícito del usuario: "ayudame a quitar o no validaciones de
     * los conductores... por ejemplo si es obligatorio o no el tema de
     * seguro" → "permiteme desde el admin poder activar o no lo
     * obligatorio para que el conductor se le haga mas facil activarse" —
     * ver App\Services\DriverVerificationRequirementRegistry.
     */
    public function test_a_disabled_requirement_no_longer_blocks_the_first_save(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        SiteSetting::current()->update(['disabled_driver_requirements' => ['has_insurance', 'police_record']]);
        $driver = User::factory()->create();

        $this->actingAs($driver)->post(route('driver.profile.update'), [
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
            // has_insurance y police_record deliberadamente ausentes.
        ])->assertSessionHasNoErrors();

        $this->assertNotNull($driver->driverProfile()->first());
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
            ->post(route('admin.driver-verifications.approve', $profile), [
                'public_category' => 'professional',
                'service_category' => 'comfort',
            ])
            ->assertRedirect();

        $this->assertSame('approved', $profile->fresh()->verification_status);
        $this->assertSame($admin->id, $profile->fresh()->verified_by);
        $this->assertSame('professional', $profile->fresh()->public_category);
        $this->assertSame('comfort', $profile->fresh()->service_category);
        Notification::assertSentTo(
            $driver,
            DriverVerificationResultPushNotification::class,
            function (DriverVerificationResultPushNotification $notification) use ($driver): bool {
                $channels = $notification->via($driver);
                $mail = $notification->toMail($driver);

                return in_array(WebPushChannel::class, $channels, true)
                    && in_array('mail', $channels, true)
                    && $mail instanceof MailMessage
                    && $mail->subject === '¡Bienvenido a la red de confianza Arka01!'
                    && $mail->actionText === 'Ir a mi panel de conductor'
                    && $mail->actionUrl === route('dashboard');
            }
        );
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

    public function test_an_admin_cannot_approve_a_driver_profile_without_declared_insurance(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $driver = User::factory()->create();
        $profile = DriverProfile::factory()->for($driver)->create([
            'verification_status' => 'pending',
            'has_insurance' => false,
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
        Notification::assertSentTo(
            $driver,
            DriverVerificationResultPushNotification::class,
            fn (DriverVerificationResultPushNotification $notification): bool => $notification->via($driver) === [WebPushChannel::class]
        );
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
