<?php

namespace Tests\Feature\Api\V1;

use App\Models\City;
use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Huecos puntuales del perfil de conductor desde la app móvil (roadmap
 * Hito 5B, paridad con la web): desactivar/reactivar el perfil, y servir
 * los documentos privados ya subidos. Reusa
 * App\Services\Driver\DriverProfileUpdater::deactivate()/reactivate()
 * (mismo servicio que tests\Feature\Driver\RoleSwitchingTest) y el mismo
 * controlador web para servir archivos
 * (tests\Feature\Security\DriverVerificationTest).
 *
 * Nota (ver feedback_sanctum_guard_test_caching en memoria): cada método de
 * test usa un solo Bearer token.
 */
class MobileDriverProfileExtrasTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_a_driver_can_deactivate_their_profile(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['is_available' => true]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->postJson('/api/v1/driver/profile/deactivate')
            ->assertOk();

        $profile = $driver->driverProfile->fresh();
        $this->assertNotNull($profile->deactivated_at);
        $this->assertFalse($profile->is_available);
        $this->assertFalse($driver->fresh()->isDriver());
    }

    public function test_deactivating_is_blocked_while_a_ride_is_in_progress(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        Ride::factory()->create(['driver_user_id' => $driver->id, 'status' => 'in_progress']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->postJson('/api/v1/driver/profile/deactivate')
            ->assertUnprocessable();
    }

    public function test_a_deactivated_driver_can_reactivate(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['deactivated_at' => now()]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->postJson('/api/v1/driver/profile/reactivate');

        $response->assertOk()->assertJsonPath('profile.deactivated_at', null);
        $this->assertTrue($driver->fresh()->isDriver());
    }

    public function test_the_owner_can_fetch_their_license_photo(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('driver-documents/licencia.jpg', 'contenido-falso');
        $driver = User::factory()->create(['is_admin' => false]);
        DriverProfile::factory()->for($driver)->create(['license_photo_path' => 'driver-documents/licencia.jpg']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->get("/api/v1/driver/{$driver->id}/license-photo")
            ->assertOk();
    }

    public function test_a_stranger_cannot_fetch_someone_elses_license_photo(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('driver-documents/licencia.jpg', 'contenido-falso');
        $driver = User::factory()->create(['is_admin' => false]);
        DriverProfile::factory()->for($driver)->create(['license_photo_path' => 'driver-documents/licencia.jpg']);
        $stranger = User::factory()->create(['is_admin' => false]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->get("/api/v1/driver/{$driver->id}/license-photo")
            ->assertForbidden();
    }

    public function test_an_admin_can_fetch_a_drivers_document(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('driver-documents/cedula.jpg', 'contenido-falso');
        $driver = User::factory()->create(['is_admin' => false]);
        DriverProfile::factory()->for($driver)->create(['identity_document_path' => 'driver-documents/cedula.jpg']);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($admin))
            ->get("/api/v1/driver/{$driver->id}/documents/identity")
            ->assertOk();
    }

    public function test_it_requires_a_token(): void
    {
        $this->postJson('/api/v1/driver/profile/deactivate')->assertUnauthorized();
    }

    /** Mismo criterio que tests\Feature\Driver\DriverCoverageSectorsTest (web) — ambos canales comparten App\Services\Driver\DriverCoverageSectorsUpdater. */
    public function test_a_driver_can_set_their_coverage_sectors(): void
    {
        $driver = User::factory()->create();
        $profile = DriverProfile::factory()->for($driver)->create();
        $city = City::query()->create(['name' => 'Guayaquil', 'province' => 'Guayas', 'is_active' => true]);
        $sector = Sector::query()->create(['city_id' => $city->id, 'name' => 'Urdesa', 'is_active' => true]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->postJson('/api/v1/driver/profile/coverage-sectors', ['sector_ids' => [$sector->id]]);

        $response->assertOk()->assertJsonPath('profile.coverage_sector_ids', [$sector->id]);
        $this->assertSame([$sector->id], $profile->coverageSectors()->pluck('sectors.id')->all());
    }
}
