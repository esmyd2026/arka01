<?php

namespace Tests\Feature\Api\V1;

use App\Models\City;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Catálogo de ciudades activas desde la app móvil (roadmap Hito 5B,
 * paridad con la web) — usado para prellenar el selector en Perfil.
 */
class MobileCityTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_only_active_cities_ordered_by_name(): void
    {
        // Las migraciones ya cargan el catálogo completo de ciudades de
        // Ecuador — solo hace falta confirmar que una inactiva no aparece.
        $user = User::factory()->create();
        City::query()->create(['name' => 'Ciudad Inactiva De Prueba', 'is_active' => false]);

        $response = $this->withHeader('Authorization', 'Bearer '.$user->createToken('test')->plainTextToken)
            ->getJson('/api/v1/cities');

        $response->assertOk();
        $names = collect($response->json('cities'))->pluck('name');
        $this->assertTrue($names->contains('Quito'));
        $this->assertFalse($names->contains('Ciudad Inactiva De Prueba'));
        $this->assertSame($names->sort()->values()->all(), $names->values()->all());
    }

    public function test_it_requires_a_token(): void
    {
        $this->getJson('/api/v1/cities')->assertUnauthorized();
    }
}
