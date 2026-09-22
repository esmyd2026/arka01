<?php

namespace Tests\Feature\Api\V1;

use App\Models\ClientCooperative;
use App\Models\Cooperative;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cooperativa como opción al pedir una carrera desde la app móvil (pedido
 * explícito del usuario: "si el conductor pertenece a una cooperativa etc").
 * Mismo criterio que CooperativeModuleTest (web) — reusa
 * App\Services\Ride\RideRequestCooperativeOptions, así que estos tests
 * confirman que el canal móvil ofrece exactamente las mismas opciones.
 */
class MobileRideRequestCooperativeTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_a_public_cooperative_is_listed_to_a_client_who_never_added_it(): void
    {
        $client = User::factory()->create();
        $publicCooperative = Cooperative::query()->create(['user_id' => User::factory()->create()->id, 'name' => 'Coop Pública', 'is_public' => true]);
        $publicCooperative->forceFill(['status' => 'approved'])->save();

        $privateCooperative = Cooperative::query()->create(['user_id' => User::factory()->create()->id, 'name' => 'Coop Privada']);
        $privateCooperative->forceFill(['status' => 'approved'])->save();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->getJson('/api/v1/ride-requests/cooperatives');

        $response->assertOk()
            ->assertJsonCount(1, 'cooperatives')
            ->assertJsonPath('cooperatives.0.name', 'Coop Pública')
            ->assertJsonPath('cooperatives.0.is_public', true);
    }

    public function test_a_client_added_cooperative_is_flagged_apart_from_a_public_one(): void
    {
        $client = User::factory()->create();

        $addedCooperative = Cooperative::query()->create(['user_id' => User::factory()->create()->id, 'name' => 'Coop De Mi Red']);
        $addedCooperative->forceFill(['status' => 'approved'])->save();
        ClientCooperative::query()->create(['client_user_id' => $client->id, 'cooperative_id' => $addedCooperative->id]);

        $publicCooperative = Cooperative::query()->create(['user_id' => User::factory()->create()->id, 'name' => 'Coop Pública', 'is_public' => true]);
        $publicCooperative->forceFill(['status' => 'approved'])->save();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->getJson('/api/v1/ride-requests/cooperatives');

        $response->assertOk();
        $cooperatives = collect($response->json('cooperatives'))->keyBy('id');
        $this->assertTrue($cooperatives[$addedCooperative->id]['is_added']);
        $this->assertFalse($cooperatives[$publicCooperative->id]['is_added']);
    }

    public function test_a_driver_cannot_list_cooperative_options(): void
    {
        $driver = User::factory()->create(['role' => 'conductor']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->getJson('/api/v1/ride-requests/cooperatives')
            ->assertOk()
            ->assertJsonCount(0, 'cooperatives');
    }

    public function test_a_client_can_request_a_ride_from_a_cooperative(): void
    {
        $client = User::factory()->create();
        $cooperative = Cooperative::query()->create(['user_id' => User::factory()->create()->id, 'name' => 'Coop Central', 'is_public' => true]);
        $cooperative->forceFill(['status' => 'approved', 'automatic_assignment_enabled' => false])->save();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->postJson('/api/v1/ride-requests', [
                'provider_type' => 'cooperative',
                'cooperative_id' => $cooperative->id,
                'origin_lat' => -2.17,
                'origin_lng' => -79.90,
                'destination_lat' => -2.18,
                'destination_lng' => -79.91,
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('ride_requests', [
            'client_user_id' => $client->id,
            'cooperative_id' => $cooperative->id,
        ]);
    }

    public function test_requesting_from_an_unattached_private_cooperative_is_rejected(): void
    {
        $client = User::factory()->create();
        $cooperative = Cooperative::query()->create(['user_id' => User::factory()->create()->id, 'name' => 'Coop Ajena']);
        $cooperative->forceFill(['status' => 'approved'])->save();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->postJson('/api/v1/ride-requests', [
                'provider_type' => 'cooperative',
                'cooperative_id' => $cooperative->id,
                'origin_lat' => -2.17,
                'origin_lng' => -79.90,
                'destination_lat' => -2.18,
                'destination_lng' => -79.91,
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('ride_requests', 0);
    }
}
