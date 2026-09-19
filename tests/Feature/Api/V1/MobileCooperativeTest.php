<?php

namespace Tests\Feature\Api\V1;

use App\Models\ClientCooperative;
use App\Models\Cooperative;
use App\Models\CooperativeDriverMembership;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Directorio de cooperativas y red de cooperativas del cliente desde la app
 * móvil (roadmap Hito 5B, paridad con la web). Reusa
 * App\Services\Cooperative\CooperativeDirectoryFinder — mismo servicio que
 * tests\Feature\CooperativeModuleTest (web).
 */
class MobileCooperativeTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_the_directory_lists_only_approved_cooperatives_and_flags_attached_ones(): void
    {
        $client = User::factory()->create();

        $attached = Cooperative::query()->create(['user_id' => User::factory()->create()->id, 'name' => 'Coop Vinculada']);
        $attached->forceFill(['status' => 'approved'])->save();
        ClientCooperative::query()->create(['client_user_id' => $client->id, 'cooperative_id' => $attached->id]);

        $other = Cooperative::query()->create(['user_id' => User::factory()->create()->id, 'name' => 'Coop Suelta']);
        $other->forceFill(['status' => 'approved'])->save();

        $pending = Cooperative::query()->create(['user_id' => User::factory()->create()->id, 'name' => 'Coop Pendiente']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->getJson('/api/v1/cooperatives');

        $response->assertOk()->assertJsonCount(2, 'cooperatives');
        $names = collect($response->json('cooperatives'))->pluck('name', 'is_attached');
        $this->assertSame('Coop Vinculada', $names->get(true));
        $this->assertSame('Coop Suelta', $names->get(false));
    }

    public function test_a_client_can_attach_an_approved_cooperative(): void
    {
        $client = User::factory()->create();
        $cooperative = Cooperative::query()->create(['user_id' => User::factory()->create()->id, 'name' => 'Coop Norte']);
        $cooperative->forceFill(['status' => 'approved'])->save();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->postJson("/api/v1/cooperatives/{$cooperative->id}/attach")
            ->assertOk();

        $this->assertDatabaseHas('client_cooperatives', [
            'client_user_id' => $client->id,
            'cooperative_id' => $cooperative->id,
        ]);
    }

    public function test_a_client_cannot_attach_a_cooperative_that_is_not_approved(): void
    {
        $client = User::factory()->create();
        $cooperative = Cooperative::query()->create(['user_id' => User::factory()->create()->id, 'name' => 'Coop Pendiente']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->postJson("/api/v1/cooperatives/{$cooperative->id}/attach")
            ->assertNotFound();
    }

    public function test_a_client_can_detach_a_cooperative(): void
    {
        $client = User::factory()->create();
        $cooperative = Cooperative::query()->create(['user_id' => User::factory()->create()->id, 'name' => 'Coop Norte']);
        $cooperative->forceFill(['status' => 'approved'])->save();
        ClientCooperative::query()->create(['client_user_id' => $client->id, 'cooperative_id' => $cooperative->id]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->deleteJson("/api/v1/cooperatives/{$cooperative->id}/detach")
            ->assertOk();

        $this->assertDatabaseMissing('client_cooperatives', [
            'client_user_id' => $client->id,
            'cooperative_id' => $cooperative->id,
        ]);
    }

    public function test_a_stranger_does_not_see_the_driver_roster_when_the_fleet_is_private(): void
    {
        $cooperativeUser = User::factory()->create();
        $cooperative = Cooperative::query()->create([
            'user_id' => $cooperativeUser->id, 'name' => 'Coop Privada', 'show_fleet_publicly' => false,
        ]);
        $cooperative->forceFill(['status' => 'approved'])->save();

        $driver = User::factory()->create();
        DriverProfile::factory()->create(['user_id' => $driver->id, 'driver_type' => 'public_transport']);
        CooperativeDriverMembership::query()->create([
            'cooperative_id' => $cooperative->id, 'driver_user_id' => $driver->id,
            'invited_by_user_id' => $cooperativeUser->id, 'status' => 'accepted', 'responded_at' => now(),
        ]);

        $viewer = User::factory()->create();
        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($viewer))
            ->getJson("/api/v1/cooperatives/{$cooperative->id}");

        $response->assertOk()
            ->assertJsonPath('fleetVisible', false)
            ->assertJsonCount(0, 'drivers')
            ->assertJsonPath('reputation.driver_count', 1);
    }

    public function test_a_non_approved_cooperative_profile_is_not_found(): void
    {
        $viewer = User::factory()->create();
        $cooperative = Cooperative::query()->create(['user_id' => User::factory()->create()->id, 'name' => 'Coop Pendiente']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($viewer))
            ->getJson("/api/v1/cooperatives/{$cooperative->id}")
            ->assertNotFound();
    }

    public function test_it_requires_a_token(): void
    {
        $this->getJson('/api/v1/cooperatives')->assertUnauthorized();
    }
}
