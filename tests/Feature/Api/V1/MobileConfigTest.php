<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Endpoint de arranque de la app móvil, sin autenticación (roadmap Hito 2).
 */
class MobileConfigTest extends TestCase
{
    // RefreshDatabase (antes este test no tocaba la base): el endpoint ahora
    // también manda el catálogo de países (pedido explícito del usuario:
    // "arka01 debe funcionar en cualquier país") — ver App\Models\Country.
    use RefreshDatabase;

    public function test_it_returns_min_version_and_maintenance_flag(): void
    {
        $response = $this->getJson('/api/v1/config');

        $response->assertOk()->assertJsonStructure(['min_version', 'maintenance', 'countries']);
    }

    public function test_it_returns_the_version_for_a_single_platform_when_asked(): void
    {
        config(['mobile.min_version.android' => '2.3.0']);

        $response = $this->getJson('/api/v1/config?platform=android');

        $response->assertOk()->assertJsonPath('min_version', '2.3.0');
    }
}
