<?php

namespace Tests\Feature\Api\V1;

use App\Models\City;
use App\Models\DriverProfile;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Directorio de conductores públicos y perfil público desde la app móvil
 * (roadmap Hito 5B, paridad con la web). Reusa
 * App\Services\Driver\DriverDirectoryFinder y
 * App\Services\Profile\PublicProfileFinder — mismos servicios que
 * tests\Feature\Directory\DriverDirectoryTest y
 * tests\Feature\PublicProfileTest.
 */
class MobileDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_directory_only_lists_public_drivers(): void
    {
        $viewer = User::factory()->create();

        $publicDriver = User::factory()->create(['name' => 'Conductor Público']);
        DriverProfile::factory()->for($publicDriver)->create([
            'is_public' => true,
            'total_points' => 500,
            'verification_status' => 'approved',
            'driver_type' => 'public_transport',
            'public_category' => 'professional',
        ]);

        $privateDriver = User::factory()->create(['name' => 'Conductor Privado']);
        DriverProfile::factory()->for($privateDriver)->create(['is_public' => false]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($viewer))
            ->getJson('/api/v1/directory');

        $response->assertOk()
            ->assertJsonCount(1, 'drivers')
            ->assertJsonPath('drivers.0.name', 'Conductor Público');
    }

    /** Mismo filtro que tests\Feature\Directory\DriverDirectoryTest::test_the_directory_can_be_filtered_by_sector(). */
    public function test_the_directory_can_be_filtered_by_sector(): void
    {
        $city = City::query()->create(['name' => 'Guayaquil', 'province' => 'Guayas', 'is_active' => true]);
        $urdesa = Sector::query()->create(['city_id' => $city->id, 'name' => 'Urdesa', 'is_active' => true]);
        $alborada = Sector::query()->create(['city_id' => $city->id, 'name' => 'Alborada', 'is_active' => true]);

        $viewer = User::factory()->create();

        $inUrdesa = User::factory()->create(['name' => 'Conductor de Urdesa']);
        $urdesaProfile = DriverProfile::factory()->for($inUrdesa)->create(['is_public' => true, 'total_points' => 500]);
        $urdesaProfile->coverageSectors()->attach($urdesa->id);

        $inAlborada = User::factory()->create(['name' => 'Conductor de Alborada']);
        $alboradaProfile = DriverProfile::factory()->for($inAlborada)->create(['is_public' => true, 'total_points' => 500]);
        $alboradaProfile->coverageSectors()->attach($alborada->id);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($viewer))
            ->getJson('/api/v1/directory?sector_id='.$urdesa->id);

        $response->assertOk()
            ->assertJsonCount(1, 'drivers')
            ->assertJsonPath('drivers.0.name', 'Conductor de Urdesa');
    }

    public function test_a_driver_cannot_browse_the_directory(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->getJson('/api/v1/directory')
            ->assertForbidden();
    }

    public function test_it_requires_a_token_for_the_directory(): void
    {
        $this->getJson('/api/v1/directory')->assertUnauthorized();
    }

    public function test_anyone_can_view_a_public_profile_without_a_token(): void
    {
        $user = User::factory()->create(['name' => 'Ana Pública']);

        $response = $this->getJson("/api/v1/profiles/{$user->public_id}");

        $response->assertOk()->assertJsonPath('profileUser.name', 'Ana Pública');
    }

    public function test_a_private_driver_profile_hides_vehicle_details_from_a_stranger(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['profile_public' => false]);
        $stranger = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->getJson("/api/v1/profiles/{$driver->public_id}");

        $response->assertOk()
            ->assertJsonPath('profilePrivate', true)
            ->assertJsonPath('profileUser.driver_profile', null);
    }

    public function test_the_owner_sees_their_own_full_private_profile(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['profile_public' => false]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->getJson("/api/v1/profiles/{$driver->public_id}");

        $response->assertOk()->assertJsonPath('profilePrivate', false);
    }
}
