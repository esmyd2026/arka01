<?php

namespace Tests\Feature\Cooperative;

use App\Models\ClientCooperative;
use App\Models\Cooperative;
use App\Models\Review;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pedido explícito del usuario: "quiero ver mis clientes vinculados el
 * detalle no tanto pero al menos la lista, cantidad de carreras,
 * puntuaccion y desvincular también" — ver App\Http\Controllers\CooperativeClientController.
 */
class CooperativeClientsTest extends TestCase
{
    use RefreshDatabase;

    private function cooperativeWithClient(): array
    {
        $cooperativeUser = User::factory()->create();
        $cooperative = Cooperative::query()->create(['user_id' => $cooperativeUser->id, 'name' => 'Coop Central']);
        $cooperative->forceFill(['status' => 'approved'])->save();

        $client = User::factory()->create(['name' => 'Gabriela Parrales']);
        $link = ClientCooperative::query()->create(['client_user_id' => $client->id, 'cooperative_id' => $cooperative->id]);

        return [$cooperativeUser, $cooperative, $client, $link];
    }

    public function test_a_regular_user_cannot_access_the_clients_screen(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('cooperative.clients.index'))->assertForbidden();
    }

    public function test_the_screen_lists_linked_clients_with_ride_and_rating_stats(): void
    {
        [$cooperativeUser, , $client] = $this->cooperativeWithClient();

        Ride::factory()->create(['client_user_id' => $client->id, 'status' => 'completed']);
        Ride::factory()->create(['client_user_id' => $client->id, 'status' => 'completed']);
        Ride::factory()->create(['client_user_id' => $client->id, 'status' => 'cancelled']);
        Review::factory()->create(['reviewee_user_id' => $client->id, 'rating' => 5]);
        Review::factory()->create(['reviewee_user_id' => $client->id, 'rating' => 3]);

        $response = $this->actingAs($cooperativeUser)->get(route('cooperative.clients.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Cooperative/Clients')
            ->has('clients', 1)
            ->where('clients.0.name', 'Gabriela Parrales')
            ->where('clients.0.completed_rides', 2)
            ->where('clients.0.cancelled_rides', 1)
            ->where('clients.0.average_rating', 4)
            ->where('clients.0.review_count', 2)
        );
    }

    public function test_a_cooperative_can_unlink_a_client(): void
    {
        [$cooperativeUser, , , $link] = $this->cooperativeWithClient();

        $this->actingAs($cooperativeUser)->delete(route('cooperative.clients.destroy', $link))->assertRedirect();

        $this->assertDatabaseMissing('client_cooperatives', ['id' => $link->id]);
    }

    public function test_a_cooperative_cannot_unlink_another_cooperatives_client(): void
    {
        [, , , $link] = $this->cooperativeWithClient();

        $otherCooperativeUser = User::factory()->create();
        Cooperative::query()->create(['user_id' => $otherCooperativeUser->id, 'name' => 'Otra Coop'])
            ->forceFill(['status' => 'approved'])->save();

        $this->actingAs($otherCooperativeUser)->delete(route('cooperative.clients.destroy', $link))->assertForbidden();

        $this->assertDatabaseHas('client_cooperatives', ['id' => $link->id]);
    }
}
