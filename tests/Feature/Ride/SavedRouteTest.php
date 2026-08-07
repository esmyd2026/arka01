<?php

namespace Tests\Feature\Ride;

use App\Models\Fleet;
use App\Models\SavedRoute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Mis rutas" (pedido explícito del usuario): guardar un origen+destino ya
 * usados, con un alias opcional, para pedir la próxima carrera sin volver a
 * escribir ni marcar nada en el mapa.
 */
class SavedRouteTest extends TestCase
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

    public function test_a_client_can_save_a_route_with_an_alias(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client)->post(route('saved-routes.store'), $this->payload + ['alias' => 'Casa-Trabajo'])
            ->assertRedirect();

        $this->assertDatabaseHas('saved_routes', [
            'client_user_id' => $client->id,
            'alias' => 'Casa-Trabajo',
        ]);
    }

    /**
     * Pedido explícito del usuario: "que no sea obligatorio" — sin alias
     * también tiene que poder guardarse.
     */
    public function test_the_alias_is_optional(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client)->post(route('saved-routes.store'), $this->payload)
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('saved_routes', ['client_user_id' => $client->id, 'alias' => null]);
    }

    public function test_a_client_can_delete_their_own_saved_route(): void
    {
        $client = User::factory()->create();
        $route = SavedRoute::factory()->for($client, 'client')->create();

        $this->actingAs($client)->delete(route('saved-routes.destroy', $route))->assertRedirect();

        $this->assertDatabaseMissing('saved_routes', ['id' => $route->id]);
    }

    public function test_a_client_cannot_delete_someone_elses_saved_route(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $route = SavedRoute::factory()->for($owner, 'client')->create();

        $this->actingAs($stranger)->delete(route('saved-routes.destroy', $route))->assertForbidden();

        $this->assertDatabaseHas('saved_routes', ['id' => $route->id]);
    }

    public function test_the_request_screen_exposes_the_clients_saved_routes(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        SavedRoute::factory()->for($client, 'client')->create(['alias' => 'Casa']);

        $response = $this->actingAs($client)->get(route('ride-requests.create', ['flota' => $fleet->id]));

        $response->assertInertia(fn ($page) => $page
            ->has('savedRoutes', 1)
            ->where('savedRoutes.0.alias', 'Casa')
        );
    }
}
