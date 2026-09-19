<?php

namespace Tests\Feature\Api\V1;

use Tests\TestCase;

/**
 * Endpoint de arranque de la app móvil, sin autenticación (roadmap Hito 2).
 */
class MobileConfigTest extends TestCase
{
    public function test_it_returns_min_version_and_maintenance_flag(): void
    {
        $response = $this->getJson('/api/v1/config');

        $response->assertOk()->assertJsonStructure(['min_version', 'maintenance']);
    }

    public function test_it_returns_the_version_for_a_single_platform_when_asked(): void
    {
        config(['mobile.min_version.android' => '2.3.0']);

        $response = $this->getJson('/api/v1/config?platform=android');

        $response->assertOk()->assertJsonPath('min_version', '2.3.0');
    }
}
