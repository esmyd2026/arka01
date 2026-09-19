<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cálculo de ruta protegido contra Google Routes desde la app móvil
 * (roadmap Hito 5B, paridad con la web) — reusa MapRouteController TAL
 * CUAL, mismo criterio que tests\Feature\MapRouteTest (web).
 */
class MobileMapRouteTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_it_returns_a_cached_google_route(): void
    {
        config()->set('services.google_maps.server_api_key', 'server-test-key');
        Cache::flush();
        Http::fake([
            'routes.googleapis.com/*' => Http::response([
                'routes' => [[
                    'distanceMeters' => 2450,
                    'duration' => '660s',
                    'polyline' => ['encodedPolyline' => '_p~iF~ps|U_ulLnnqC'],
                ]],
            ]),
        ]);

        $user = User::factory()->create();
        $payload = [
            'origin_lat' => -2.1376,
            'origin_lng' => -79.8942,
            'destination_lat' => -2.1501,
            'destination_lng' => -79.9012,
        ];

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/v1/maps/route', $payload)
            ->assertOk()
            ->assertJson([
                'encoded_polyline' => '_p~iF~ps|U_ulLnnqC',
                'distance_km' => 2.45,
                'duration_min' => 11,
            ]);

        Http::assertSentCount(1);
    }

    public function test_it_requires_valid_coordinates(): void
    {
        $user = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/v1/maps/route', ['origin_lat' => 999, 'origin_lng' => -79])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['origin_lat', 'destination_lat', 'destination_lng']);
    }

    public function test_it_requires_a_token(): void
    {
        $this->postJson('/api/v1/maps/route', [])->assertUnauthorized();
    }
}
