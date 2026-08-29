<?php

namespace Tests\Feature\TrustCircle;

use App\Events\FleetInvitationCreated;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\TrustCircleConnection;
use App\Models\TrustCircleSetting;
use App\Models\User;
use App\Services\Trust\TrustIndexCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TrustCircleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_person_enters_the_circle_only_after_accepting(): void
    {
        $requester = User::factory()->create();
        $relative = User::factory()->create();

        $this->actingAs($requester)->post(route('trust-circle.store'), [
            'user_public_id' => $relative->public_id,
            'relationship_label' => 'Familia',
        ])->assertRedirect();

        $connection = TrustCircleConnection::query()->firstOrFail();
        $this->assertSame('pending', $connection->status);
        $this->assertDatabaseMissing('trust_circle_settings', [
            'connection_id' => $connection->id,
            'user_id' => $relative->id,
        ]);

        $this->actingAs($relative)->post(route('trust-circle.respond', $connection), [
            'action' => 'accept',
        ])->assertRedirect();

        $this->assertDatabaseHas('trust_circle_connections', [
            'id' => $connection->id,
            'status' => 'accepted',
        ]);
        $this->assertDatabaseHas('trust_circle_settings', [
            'connection_id' => $connection->id,
            'user_id' => $requester->id,
            'relationship_label' => 'Familia',
        ]);
        $this->assertDatabaseHas('trust_circle_settings', [
            'connection_id' => $connection->id,
            'user_id' => $relative->id,
            'share_fleet' => false,
            'share_rating' => true,
        ]);
    }

    public function test_search_does_not_expose_phone_email_or_numeric_user_id(): void
    {
        $user = User::factory()->create();
        $friend = User::factory()->create();

        $response = $this->actingAs($user)->getJson(route('trust-circle.search', ['q' => $friend->username]));

        $response->assertOk()
            ->assertJsonPath('people.0.user_public_id', $friend->public_id)
            ->assertJsonMissingPath('people.0.id')
            ->assertJsonMissingPath('people.0.phone')
            ->assertJsonMissingPath('people.0.email');
    }

    public function test_search_finds_people_by_partial_first_name_last_name_or_full_name(): void
    {
        $user = User::factory()->create();
        $friend = User::factory()->create([
            'name' => 'Doris',
            'last_name' => 'Tapia Mendoza',
        ]);

        foreach (['Dor', 'Tapia', 'Doris Tapia'] as $term) {
            $this->actingAs($user)
                ->getJson(route('trust-circle.search', ['q' => $term]))
                ->assertOk()
                ->assertJsonPath('people.0.user_public_id', $friend->public_id);
        }
    }

    public function test_each_person_controls_their_own_directional_privacy(): void
    {
        [$connection, $first, $second] = $this->acceptedConnection();

        $this->actingAs($first)->put(route('trust-circle.settings.update', $connection), [
            'relationship_label' => 'Hermana',
            'share_fleet' => true,
            'share_rating' => false,
        ])->assertRedirect();

        $this->assertDatabaseHas('trust_circle_settings', [
            'connection_id' => $connection->id,
            'user_id' => $first->id,
            'relationship_label' => 'Hermana',
            'share_fleet' => true,
            'share_rating' => false,
        ]);
        $this->assertDatabaseHas('trust_circle_settings', [
            'connection_id' => $connection->id,
            'user_id' => $second->id,
            'share_fleet' => false,
            'share_rating' => true,
        ]);
    }

    public function test_a_shared_circle_fleet_recommends_its_drivers_without_adding_them_automatically(): void
    {
        Notification::fake();
        Event::fake([FleetInvitationCreated::class]);

        [$connection, $client, $friend] = $this->acceptedConnection();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $friendFleet = Fleet::factory()->for($friend, 'owner')->create();
        FleetMember::factory()->for($friendFleet)->for($driver, 'driver')->create(['added_by' => $friend->id]);

        TrustCircleSetting::query()
            ->where('connection_id', $connection->id)
            ->where('user_id', $friend->id)
            ->update(['share_fleet' => true]);

        $this->actingAs($client)->get(route('trust-circle.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('TrustCircle/Index')
                ->where('recommendedDrivers.0.driver_public_id', $driver->public_id)
                ->where('recommendedDrivers.0.recommended_by_count', 1));

        $this->assertDatabaseMissing('fleet_members', [
            'driver_user_id' => $driver->id,
            'added_by' => $client->id,
        ]);

        $this->actingAs($client)->post(route('trust-circle.drivers.invite'), [
            'driver_public_id' => $driver->public_id,
        ])->assertRedirect();

        $clientFleet = Fleet::query()->where('owner_user_id', $client->id)->firstOrFail();
        $this->assertDatabaseHas('fleet_invitations', [
            'fleet_id' => $clientFleet->id,
            'driver_user_id' => $driver->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseMissing('fleet_members', [
            'fleet_id' => $clientFleet->id,
            'driver_user_id' => $driver->id,
        ]);
    }

    public function test_the_index_is_calculated_from_each_subjects_role_and_exposes_components(): void
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $calculator = app(TrustIndexCalculator::class);

        $clientIndex = $calculator->calculate($client, $driver);
        $driverIndex = $calculator->calculate($driver, $client);

        $this->assertSame('Cliente', $clientIndex['role']);
        $this->assertSame('Conductor', $driverIndex['role']);
        $this->assertCount(4, $clientIndex['components']);
        $this->assertCount(4, $driverIndex['components']);
        $this->assertGreaterThanOrEqual(0, $clientIndex['score']);
        $this->assertLessThanOrEqual(100, $driverIndex['score']);
    }

    private function acceptedConnection(): array
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $connection = TrustCircleConnection::query()->create([
            'requester_user_id' => $first->id,
            'addressee_user_id' => $second->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);
        foreach ([$first, $second] as $user) {
            TrustCircleSetting::query()->create([
                'connection_id' => $connection->id,
                'user_id' => $user->id,
                'share_fleet' => false,
                'share_rating' => true,
            ]);
        }

        return [$connection, $first, $second];
    }
}
