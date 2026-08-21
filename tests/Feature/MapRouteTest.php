<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MapRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_request_a_cached_google_route(): void
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

        $payload = [
            'origin_lat' => -2.1376,
            'origin_lng' => -79.8942,
            'destination_lat' => -2.1501,
            'destination_lng' => -79.9012,
        ];
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('maps.route'), $payload)
            ->assertOk()
            ->assertJson([
                'encoded_polyline' => '_p~iF~ps|U_ulLnnqC',
                'distance_km' => 2.45,
                'duration_min' => 11,
            ]);

        // La segunda consulta equivalente debe salir de caché.
        $this->actingAs($user)->postJson(route('maps.route'), $payload)->assertOk();
        Http::assertSentCount(1);
    }

    public function test_route_endpoint_requires_valid_coordinates(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson(route('maps.route'), [
                'origin_lat' => 999,
                'origin_lng' => -79,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['origin_lat', 'destination_lat', 'destination_lng']);
    }
}
