<?php

namespace Tests\Feature\Api\V1;

use App\Models\SavedRoute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Mis rutas" desde la app móvil (roadmap Hito 5B, paridad con la web) —
 * mismas reglas que tests\Feature\Ride\SavedRouteTest.
 */
class MobileSavedRouteTest extends TestCase
{
    use RefreshDatabase;

    private array $payload = [
        'origin_lat' => -2.1807,
        'origin_lng' => -79.8678,
        'origin_address' => 'Casa',
        'destination_lat' => -2.1500,
        'destination_lng' => -79.9000,
        'destination_address' => 'Oficina',
    ];

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_a_client_can_save_a_route_with_an_alias(): void
    {
        $client = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->postJson('/api/v1/saved-routes', $this->payload + ['alias' => 'Casa-Trabajo'])
            ->assertCreated();

        $this->assertDatabaseHas('saved_routes', [
            'client_user_id' => $client->id,
            'alias' => 'Casa-Trabajo',
        ]);
    }

    public function test_the_alias_is_optional(): void
    {
        $client = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->postJson('/api/v1/saved-routes', $this->payload)
            ->assertCreated();

        $this->assertDatabaseHas('saved_routes', ['client_user_id' => $client->id, 'alias' => null]);
    }

    public function test_it_lists_the_clients_saved_routes(): void
    {
        $client = User::factory()->create();
        SavedRoute::factory()->for($client, 'client')->create(['alias' => 'Casa']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->getJson('/api/v1/saved-routes')
            ->assertOk()
            ->assertJsonCount(1, 'saved_routes');
    }

    public function test_a_client_can_delete_their_own_saved_route(): void
    {
        $client = User::factory()->create();
        $route = SavedRoute::factory()->for($client, 'client')->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->deleteJson("/api/v1/saved-routes/{$route->id}")
            ->assertOk();

        $this->assertDatabaseMissing('saved_routes', ['id' => $route->id]);
    }

    public function test_a_client_cannot_delete_someone_elses_saved_route(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $route = SavedRoute::factory()->for($owner, 'client')->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->deleteJson("/api/v1/saved-routes/{$route->id}")
            ->assertForbidden();
    }

    public function test_it_requires_a_token(): void
    {
        $this->getJson('/api/v1/saved-routes')->assertUnauthorized();
    }
}
