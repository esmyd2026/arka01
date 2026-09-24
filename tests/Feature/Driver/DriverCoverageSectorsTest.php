<?php

namespace Tests\Feature\Driver;

use App\Models\City;
use App\Models\DriverProfile;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Zona de trabajo del conductor por sector con nombre (pedido explícito del
 * usuario: "que los conductores puedan indicar la zona de trabajo", para
 * que el cliente vea a los conductores de su sector en el directorio). Web
 * y móvil comparten App\Services\Driver\DriverCoverageSectorsUpdater — este
 * archivo cubre el lado web; tests/Feature/Api/V1/MobileDriverProfileTest.php
 * y MobileDirectoryTest.php cubren el móvil.
 */
class DriverCoverageSectorsTest extends TestCase
{
    use RefreshDatabase;

    private function sector(string $name = 'Urdesa'): Sector
    {
        $city = City::query()->create(['name' => 'Guayaquil', 'province' => 'Guayas', 'is_active' => true]);

        return Sector::query()->create(['city_id' => $city->id, 'name' => $name, 'is_active' => true]);
    }

    public function test_a_driver_can_set_their_coverage_sectors(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $sectorA = $this->sector('Urdesa');
        $sectorB = $this->sector('Alborada');

        $this->actingAs($driver)
            ->post(route('driver.profile.coverage-sectors'), ['sector_ids' => [$sectorA->id, $sectorB->id]])
            ->assertRedirect();

        $this->assertSame(
            [$sectorA->id, $sectorB->id],
            $driver->driverProfile->coverageSectors()->orderBy('sectors.id')->pluck('sectors.id')->all()
        );
    }

    public function test_setting_coverage_sectors_replaces_the_previous_set(): void
    {
        $driver = User::factory()->create();
        $profile = DriverProfile::factory()->for($driver)->create();
        $sectorA = $this->sector('Urdesa');
        $sectorB = $this->sector('Alborada');
        $profile->coverageSectors()->attach($sectorA->id);

        $this->actingAs($driver)
            ->post(route('driver.profile.coverage-sectors'), ['sector_ids' => [$sectorB->id]])
            ->assertRedirect();

        $this->assertSame([$sectorB->id], $profile->coverageSectors()->pluck('sectors.id')->all());
    }

    public function test_an_inactive_sector_id_is_silently_ignored(): void
    {
        $driver = User::factory()->create();
        $profile = DriverProfile::factory()->for($driver)->create();
        $inactive = $this->sector('Zona Vieja');
        $inactive->update(['is_active' => false]);

        $this->actingAs($driver)
            ->post(route('driver.profile.coverage-sectors'), ['sector_ids' => [$inactive->id]])
            ->assertRedirect();

        $this->assertSame(0, $profile->coverageSectors()->count());
    }

    public function test_a_client_cannot_set_coverage_sectors(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client)
            ->post(route('driver.profile.coverage-sectors'), ['sector_ids' => []])
            ->assertNotFound();
    }
}
