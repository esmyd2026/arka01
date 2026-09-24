<?php

namespace Tests\Feature\Directory;

use App\Models\City;
use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mapa de "conductores cerca de mí" (pedido explícito del usuario, casi
 * textual): "un mapa... con la ubicación actual y una barra arriba que
 * indique el radio de 2km por defecto... y que vayan apareciendo los
 * conductores cercanos o dentro de ese rango... los activos o inactivos".
 * Reemplazó, en la web, a la lista paginada con filtro por sector (esa
 * lógica sigue viva para la app móvil en
 * App\Services\Driver\DriverDirectoryFinder::browse(), ver
 * tests\Feature\Api\V1\MobileDirectoryTest).
 */
class DriverNearbyMapTest extends TestCase
{
    use RefreshDatabase;

    // Origen de referencia: centro de Quito.
    private const LAT = -0.1807;

    private const LNG = -78.4678;

    public function test_it_requires_coordinates(): void
    {
        $viewer = User::factory()->create();

        $this->actingAs($viewer)
            ->getJson(route('directory.nearby'))
            ->assertUnprocessable();
    }

    public function test_it_only_lists_public_drivers_within_the_radius(): void
    {
        $viewer = User::factory()->create();

        $near = User::factory()->create(['name' => 'Cerca']);
        DriverProfile::factory()->for($near)->create([
            'is_public' => true,
            'total_points' => 500,
            // ~0.3 km del origen — bien adentro del radio por defecto (2km).
            'current_lat' => self::LAT + 0.003,
            'current_lng' => self::LNG,
        ]);

        $far = User::factory()->create(['name' => 'Lejos']);
        DriverProfile::factory()->for($far)->create([
            'is_public' => true,
            'total_points' => 500,
            // Bien afuera de 2km.
            'current_lat' => self::LAT + 0.5,
            'current_lng' => self::LNG,
        ]);

        $response = $this->actingAs($viewer)
            ->getJson(route('directory.nearby', ['lat' => self::LAT, 'lng' => self::LNG]));

        $response->assertOk()
            ->assertJsonCount(1, 'drivers')
            ->assertJsonPath('drivers.0.name', 'Cerca')
            ->assertJsonPath('radiusKm', 2);
    }

    public function test_a_wider_radius_reaches_farther_drivers(): void
    {
        $viewer = User::factory()->create();

        $far = User::factory()->create(['name' => 'A 4km']);
        DriverProfile::factory()->for($far)->create([
            'is_public' => true,
            'total_points' => 500,
            'current_lat' => self::LAT + 0.036, // ~4km
            'current_lng' => self::LNG,
        ]);

        $response = $this->actingAs($viewer)
            ->getJson(route('directory.nearby', ['lat' => self::LAT, 'lng' => self::LNG, 'radius_km' => 5]));

        $response->assertOk()
            ->assertJsonCount(1, 'drivers')
            ->assertJsonPath('drivers.0.name', 'A 4km');
    }

    /** Fidelización por puntos, mismo criterio que el resto del directorio. */
    public function test_a_driver_below_the_public_eligible_tier_does_not_appear(): void
    {
        $viewer = User::factory()->create();

        $belowTier = User::factory()->create(['name' => 'Todavía No Llega']);
        DriverProfile::factory()->for($belowTier)->create([
            'is_public' => true,
            'total_points' => 100,
            'current_lat' => self::LAT,
            'current_lng' => self::LNG,
        ]);

        $response = $this->actingAs($viewer)
            ->getJson(route('directory.nearby', ['lat' => self::LAT, 'lng' => self::LNG]));

        $response->assertOk()->assertJsonCount(0, 'drivers');
    }

    /**
     * Bug real que este test evita: sin ubicación en vivo no hay forma de
     * pintar un pin en el mapa ni de saber si está de verdad cerca — un
     * conductor público que nunca compartió su posición simplemente no
     * puede aparecer acá (sí sigue apareciendo en browse(), la lista de la
     * app móvil, que no depende de un punto de búsqueda).
     */
    public function test_a_driver_without_a_live_location_is_excluded(): void
    {
        $viewer = User::factory()->create();

        $noLocation = User::factory()->create(['name' => 'Sin Ubicación']);
        DriverProfile::factory()->for($noLocation)->create([
            'is_public' => true,
            'total_points' => 500,
            'current_lat' => null,
            'current_lng' => null,
        ]);

        $response = $this->actingAs($viewer)
            ->getJson(route('directory.nearby', ['lat' => self::LAT, 'lng' => self::LNG]));

        $response->assertOk()->assertJsonCount(0, 'drivers');
    }

    /**
     * Pedido explícito del usuario: "que le aparezcan los activos o
     * inactivos" — a diferencia del panel admin de operaciones en vivo, acá
     * NO se excluye a quien no está disponible ahora mismo.
     */
    public function test_it_lists_both_available_and_unavailable_drivers(): void
    {
        $viewer = User::factory()->create();

        $available = User::factory()->create(['name' => 'Disponible']);
        DriverProfile::factory()->for($available)->create([
            'is_public' => true, 'total_points' => 500, 'is_available' => true,
            'current_lat' => self::LAT, 'current_lng' => self::LNG,
        ]);

        $unavailable = User::factory()->create(['name' => 'No Disponible']);
        DriverProfile::factory()->for($unavailable)->create([
            'is_public' => true, 'total_points' => 500, 'is_available' => false,
            'current_lat' => self::LAT, 'current_lng' => self::LNG,
        ]);

        $response = $this->actingAs($viewer)
            ->getJson(route('directory.nearby', ['lat' => self::LAT, 'lng' => self::LNG]));

        $response->assertOk()->assertJsonCount(2, 'drivers');
    }

    public function test_it_sorts_results_by_distance(): void
    {
        $viewer = User::factory()->create();

        $farther = User::factory()->create(['name' => 'Más Lejos']);
        DriverProfile::factory()->for($farther)->create([
            'is_public' => true, 'total_points' => 500,
            'current_lat' => self::LAT + 0.015, 'current_lng' => self::LNG,
        ]);

        $closer = User::factory()->create(['name' => 'Más Cerca']);
        DriverProfile::factory()->for($closer)->create([
            'is_public' => true, 'total_points' => 500,
            'current_lat' => self::LAT + 0.001, 'current_lng' => self::LNG,
        ]);

        $response = $this->actingAs($viewer)
            ->getJson(route('directory.nearby', ['lat' => self::LAT, 'lng' => self::LNG]));

        $response->assertOk()
            ->assertJsonPath('drivers.0.name', 'Más Cerca')
            ->assertJsonPath('drivers.1.name', 'Más Lejos');
    }

    /**
     * Pedido explícito del usuario: "cant carreras" en la tarjeta — carreras
     * completadas, mismo criterio que FleetRosterBuilder.
     */
    public function test_it_shows_completed_rides_count(): void
    {
        $viewer = User::factory()->create();

        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create([
            'is_public' => true, 'total_points' => 500,
            'current_lat' => self::LAT, 'current_lng' => self::LNG,
        ]);
        Ride::factory()->count(3)->create(['driver_user_id' => $driver->id, 'status' => 'completed']);
        Ride::factory()->create(['driver_user_id' => $driver->id, 'status' => 'cancelled']);

        $response = $this->actingAs($viewer)
            ->getJson(route('directory.nearby', ['lat' => self::LAT, 'lng' => self::LNG]));

        $response->assertOk()->assertJsonPath('drivers.0.rides_count', 3);
    }

    /**
     * Pedido explícito del usuario: "debemos identificarlos también" — un
     * conductor que está cerca AHORA aparece igual aunque no haya declarado
     * esta zona como su cobertura; la zona declarada viaja solo como dato
     * informativo adicional, no como filtro.
     */
    public function test_it_shows_declared_coverage_sectors_as_informational_data(): void
    {
        $viewer = User::factory()->create();

        $driver = User::factory()->create();
        $profile = DriverProfile::factory()->for($driver)->create([
            'is_public' => true, 'total_points' => 500,
            'current_lat' => self::LAT, 'current_lng' => self::LNG,
        ]);
        $city = City::query()->create(['name' => 'Guayaquil', 'province' => 'Guayas', 'is_active' => true]);
        $sector = Sector::query()->create(['city_id' => $city->id, 'name' => 'Urdesa', 'is_active' => true]);
        $profile->coverageSectors()->attach($sector->id);

        $response = $this->actingAs($viewer)
            ->getJson(route('directory.nearby', ['lat' => self::LAT, 'lng' => self::LNG]));

        $response->assertOk()->assertJsonPath('drivers.0.coverage_sectors.0.name', 'Urdesa');
    }

    public function test_a_driver_cannot_use_this_endpoint(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $this->actingAs($driver)
            ->getJson(route('directory.nearby', ['lat' => self::LAT, 'lng' => self::LNG]))
            ->assertForbidden();
    }

    public function test_it_requires_authentication(): void
    {
        // XHR/JSON sin sesión: Laravel responde 401 en vez de redirigir al login.
        $this->getJson(route('directory.nearby', ['lat' => self::LAT, 'lng' => self::LNG]))
            ->assertUnauthorized();
    }
}
