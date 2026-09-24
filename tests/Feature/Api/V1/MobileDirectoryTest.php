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

    /**
     * Fidelización por puntos (pedido explícito del usuario): un conductor
     * con plan que habilita el directorio pero sin medalla suficiente (por
     * debajo de Oro, la semilla de App\Models\DriverTier) no aparece — la
     * visibilidad ahora también se gana con carreras completadas, no solo se
     * paga. Cobertura de App\Services\Driver\DriverDirectoryFinder::browse(),
     * movida acá desde tests\Feature\Directory\DriverDirectoryTest cuando la
     * web pasó a usar el mapa de "conductores cerca de mí" (nearby()) en vez
     * de esta lista paginada — browse() lo sigue usando la app móvil tal cual.
     */
    public function test_a_driver_below_the_public_eligible_tier_does_not_appear_even_if_public(): void
    {
        $viewer = User::factory()->create();

        $belowTier = User::factory()->create(['name' => 'Todavía No Llega']);
        DriverProfile::factory()->for($belowTier)->create(['is_public' => true, 'total_points' => 100]);

        $atTier = User::factory()->create(['name' => 'Ya Llegó']);
        DriverProfile::factory()->for($atTier)->create(['is_public' => true, 'total_points' => 500]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($viewer))
            ->getJson('/api/v1/directory');

        $response->assertOk()
            ->assertJsonCount(1, 'drivers')
            ->assertJsonPath('drivers.0.name', 'Ya Llegó');
    }

    /** Diamante por encima de Oro — mismo criterio de origen que la nota arriba. */
    public function test_a_diamond_driver_is_listed_before_a_gold_driver(): void
    {
        $viewer = User::factory()->create();

        $gold = User::factory()->create(['name' => 'Conductor Oro']);
        DriverProfile::factory()->for($gold)->create(['is_public' => true, 'total_points' => 500]);

        $diamond = User::factory()->create(['name' => 'Conductor Diamante']);
        DriverProfile::factory()->for($diamond)->create(['is_public' => true, 'total_points' => 1000]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($viewer))
            ->getJson('/api/v1/directory');

        $response->assertOk()
            ->assertJsonPath('drivers.0.name', 'Conductor Diamante')
            ->assertJsonPath('drivers.1.name', 'Conductor Oro');
    }

    /**
     * Confidencialidad (pedido explícito del usuario): la foto del vehículo
     * ya no se manda al directorio público — solo el propio conductor y un
     * admin la ven. El tipo de vehículo (SUV, sedán, etc.) es lo que la
     * reemplaza acá. Mismo criterio de origen que las dos notas de arriba.
     */
    public function test_the_directory_does_not_expose_the_vehicle_photo_and_shows_the_vehicle_type(): void
    {
        $viewer = User::factory()->create();

        $driver = User::factory()->create(['name' => 'Conductor Público']);
        DriverProfile::factory()->for($driver)->create([
            'is_public' => true,
            'total_points' => 500,
            'vehicle_type' => 'suv',
            'vehicle_photo_path' => 'driver-documents/vehiculo.jpg',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($viewer))
            ->getJson('/api/v1/directory');

        $response->assertOk()
            ->assertJsonMissingPath('drivers.0.vehicle_photo_url')
            ->assertJsonPath('drivers.0.vehicle_type', 'SUV');
    }

    /** Filtro por sector declarado (App\Models\DriverProfile::coverageSectors()), solo disponible en esta lista paginada — el mapa web (nearby()) filtra por radio, no por sector. */
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
