<?php

namespace Tests\Feature\Api\V1;

use App\Events\FleetInvitationCreated;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\TrustCircleConnection;
use App\Models\TrustCircleSetting;
use App\Models\User;
use App\Notifications\TrustCircleRequestPushNotification;
use App\Notifications\TrustCircleResponsePushNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Círculo de confianza desde la app móvil (roadmap Hito 5B, paridad con la
 * web). Reusa App\Services\Trust\TrustCircleManager — mismo servicio que
 * tests\Feature\TrustCircle\TrustCircleTest (web).
 */
class MobileTrustCircleTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_a_request_stays_pending_until_the_relative_responds(): void
    {
        Notification::fake();
        $requester = User::factory()->create();
        $relative = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($requester))
            ->postJson('/api/v1/trust-circle', [
                'user_public_id' => $relative->public_id,
                'relationship_label' => 'Familia',
            ])->assertCreated();

        $connection = TrustCircleConnection::query()->firstOrFail();
        Notification::assertSentTo($relative, TrustCircleRequestPushNotification::class);
        $this->assertSame('pending', $connection->status);
    }

    public function test_the_relative_sees_and_can_accept_a_pending_request(): void
    {
        Notification::fake();
        $requester = User::factory()->create();
        $relative = User::factory()->create();
        $connection = TrustCircleConnection::query()->create([
            'requester_user_id' => $requester->id,
            'addressee_user_id' => $relative->id,
            'status' => 'pending',
        ]);
        $connection->settings()->create([
            'user_id' => $requester->id,
            'relationship_label' => 'Familia',
            'share_fleet' => false,
            'share_rating' => true,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($relative))
            ->getJson('/api/v1/trust-circle')
            ->assertOk()
            ->assertJsonPath('receivedRequests.0.user_public_id', $requester->public_id)
            ->assertJsonPath('receivedRequests.0.relationship_label', 'Familia');

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($relative))
            ->postJson("/api/v1/trust-circle/{$connection->public_id}/respond", ['action' => 'accept'])
            ->assertOk();

        $this->assertDatabaseHas('trust_circle_connections', ['id' => $connection->id, 'status' => 'accepted']);
        Notification::assertSentTo(
            $requester,
            TrustCircleResponsePushNotification::class,
            fn (TrustCircleResponsePushNotification $notification) => $notification->accepted,
        );
    }

    public function test_search_does_not_expose_phone_email_or_numeric_user_id(): void
    {
        $user = User::factory()->create();
        $friend = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/v1/trust-circle/search?q='.$friend->username);

        $response->assertOk()
            ->assertJsonPath('people.0.user_public_id', $friend->public_id)
            ->assertJsonMissingPath('people.0.id')
            ->assertJsonMissingPath('people.0.phone')
            ->assertJsonMissingPath('people.0.email');
    }

    public function test_each_person_controls_their_own_directional_privacy(): void
    {
        [$connection, $first, $second] = $this->acceptedConnection();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($first))
            ->putJson("/api/v1/trust-circle/{$connection->public_id}/settings", [
                'relationship_label' => 'Hermana',
                'share_fleet' => true,
                'share_rating' => false,
            ])->assertOk();

        $this->assertDatabaseHas('trust_circle_settings', [
            'connection_id' => $connection->id,
            'user_id' => $first->id,
            'relationship_label' => 'Hermana',
            'share_fleet' => true,
            'share_rating' => false,
        ]);
    }

    public function test_a_connection_can_be_removed(): void
    {
        [$connection, $first] = $this->acceptedConnection();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($first))
            ->deleteJson("/api/v1/trust-circle/{$connection->public_id}")
            ->assertOk();

        $this->assertDatabaseMissing('trust_circle_connections', ['id' => $connection->id]);
    }

    public function test_a_shared_circle_fleet_recommends_its_drivers_without_adding_them_automatically(): void
    {
        Notification::fake();
        Event::fake([FleetInvitationCreated::class]);

        [$connection, $client, $friend] = $this->acceptedConnection();
        $driver = User::factory()->create();
        // Este test verifica el status 'pending' de la invitación en sí — la
        // aprobación automática (default) no es lo que se está probando acá.
        DriverProfile::factory()->for($driver)->create(['requires_fleet_invitation_approval' => true]);
        $friendFleet = Fleet::factory()->for($friend, 'owner')->create();
        FleetMember::factory()->for($friendFleet)->for($driver, 'driver')->create(['added_by' => $friend->id]);

        TrustCircleSetting::query()
            ->where('connection_id', $connection->id)
            ->where('user_id', $friend->id)
            ->update(['share_fleet' => true]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->getJson('/api/v1/trust-circle')
            ->assertOk()
            ->assertJsonPath('recommendedDrivers.0.driver_public_id', $driver->public_id);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->postJson('/api/v1/trust-circle/drivers/invite', ['driver_public_id' => $driver->public_id])
            ->assertOk();

        $clientFleet = Fleet::query()->where('owner_user_id', $client->id)->firstOrFail();
        $this->assertDatabaseHas('fleet_invitations', [
            'fleet_id' => $clientFleet->id,
            'driver_user_id' => $driver->id,
            'status' => 'pending',
        ]);
    }

    public function test_it_requires_a_token(): void
    {
        $this->getJson('/api/v1/trust-circle')->assertUnauthorized();
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
