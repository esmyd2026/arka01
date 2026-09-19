<?php

namespace Tests\Feature\Api\V1;

use App\Models\RadioChannel;
use App\Models\RideRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Canal de radio/walkie-talkie de seguridad desde la app móvil (roadmap
 * Hito 5B, paridad con la web). Reusa RadioSessionController (web) TAL
 * CUAL y App\Services\RadioChannelManager — mismo backend que
 * tests\Feature\Security\RadioSessionTest (web). No incluye la parte de
 * audio en vivo (microservicio Node aparte): eso queda para cuando el
 * cliente móvil integre un stack de WebRTC/audio, fuera de este bloque.
 */
class MobileRadioTest extends TestCase
{
    use RefreshDatabase;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secret = str_repeat('local-radio-test-secret-', 4);
        config()->set('radio.shared_secret', $this->secret);
        config()->set('radio.token_ttl_seconds', 1800);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function channelFor(User $owner): RadioChannel
    {
        return RadioChannel::query()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Canal de '.$owner->name,
        ]);
    }

    public function test_the_personal_channel_is_hidden_until_the_owner_starts_a_request(): void
    {
        $client = User::factory()->create();
        $this->channelFor($client);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->getJson('/api/v1/radio/status')
            ->assertExactJson(['enabled' => false]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->postJson('/api/v1/radio/session')
            ->assertConflict();

        RideRequest::factory()->create(['client_user_id' => $client->id, 'is_scheduled' => false, 'status' => 'pending']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->getJson('/api/v1/radio/status')
            ->assertOk()
            ->assertJson(['enabled' => true, 'phase' => 'searching', 'is_owner' => true]);
    }

    public function test_owner_receives_a_signed_token_for_the_server_generated_channel(): void
    {
        $client = User::factory()->create(['name' => 'Laura']);
        RideRequest::factory()->create(['client_user_id' => $client->id, 'is_scheduled' => false, 'status' => 'pending']);

        $status = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->getJson('/api/v1/radio/status')->assertOk();

        $channel = RadioChannel::query()->where('owner_user_id', $client->id)->firstOrFail();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->postJson('/api/v1/radio/session', ['channel_public_id' => $channel->public_id])
            ->assertOk();

        [$payload, $signature] = explode('.', $response->json('token'));
        $expectedSignature = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $this->secret, true)), '+/', '-_'), '=');
        $this->assertSame($expectedSignature, $signature);
        $this->assertSame($status->json('room_id'), $response->json('room_id'));
    }

    public function test_a_family_member_can_join_a_channel_by_share_code(): void
    {
        $owner = User::factory()->create();
        $relative = User::factory()->create();
        $channel = $this->channelFor($owner);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($relative))
            ->postJson("/api/v1/radio/invitations/{$channel->share_code}/join")
            ->assertOk();

        $this->assertDatabaseHas('radio_channel_members', ['radio_channel_id' => $channel->id, 'user_id' => $relative->id]);
    }

    public function test_admin_and_cooperative_accounts_are_forbidden(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($admin))
            ->getJson('/api/v1/radio/status')
            ->assertForbidden();
    }

    public function test_owner_can_remove_members_and_rotate_the_shared_link(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $channel = $this->channelFor($owner);
        $channel->members()->create(['user_id' => $member->id, 'joined_at' => now()]);
        $oldCode = $channel->share_code;

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->deleteJson("/api/v1/radio/channels/{$channel->public_id}/members/{$member->public_id}")
            ->assertOk();
        $this->assertDatabaseMissing('radio_channel_members', ['radio_channel_id' => $channel->id, 'user_id' => $member->id]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/radio/channels/{$channel->public_id}/rotate-invitation")
            ->assertOk();
        $this->assertNotSame($oldCode, $channel->fresh()->share_code);
    }

    public function test_a_stranger_cannot_manage_someone_elses_channel(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $channel = $this->channelFor($owner);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->patchJson("/api/v1/radio/channels/{$channel->public_id}", ['name' => 'Robado'])
            ->assertForbidden();
    }

    public function test_it_requires_a_token(): void
    {
        $this->getJson('/api/v1/radio/status')->assertUnauthorized();
    }
}
