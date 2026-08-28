<?php

namespace Tests\Feature\Security;

use App\Models\Cooperative;
use App\Models\DriverProfile;
use App\Models\RadioChannel;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RadioSessionTest extends TestCase
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

    public function test_a_guest_cannot_check_or_open_radio_but_can_view_an_invitation(): void
    {
        $owner = User::factory()->create();
        $channel = $this->channelFor($owner);

        $this->getJson(route('radio.status'))->assertUnauthorized();
        $this->postJson(route('radio.session'))->assertUnauthorized();
        $this->get(route('radio.invitation.show', $channel->share_code))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Radio/Invitation')
                ->where('channel.owner.name', $owner->full_name));
    }

    public function test_personal_channel_is_hidden_until_its_owner_starts_a_request(): void
    {
        $client = User::factory()->create();
        $this->channelFor($client);

        $this->actingAs($client)->getJson(route('radio.status'))->assertExactJson(['enabled' => false]);
        $this->actingAs($client)->postJson(route('radio.session'))->assertConflict();

        RideRequest::factory()->create([
            'client_user_id' => $client->id,
            'is_scheduled' => false,
            'status' => 'pending',
        ]);

        $this->actingAs($client)->getJson(route('radio.status'))
            ->assertOk()
            ->assertJson(['enabled' => true, 'phase' => 'searching', 'is_owner' => true]);
    }

    public function test_owner_receives_a_signed_token_for_the_server_generated_channel(): void
    {
        $client = User::factory()->create(['name' => 'Laura']);
        RideRequest::factory()->create([
            'client_user_id' => $client->id,
            'is_scheduled' => false,
            'status' => 'pending',
        ]);

        $status = $this->actingAs($client)->getJson(route('radio.status'))->assertOk();
        $channel = RadioChannel::query()->where('owner_user_id', $client->id)->firstOrFail();
        $expectedRoom = $this->roomFor($channel);
        $this->assertSame($expectedRoom, $status->json('room_id'));
        $this->assertStringEndsWith($channel->share_code, $status->json('invite_url'));
        $this->assertStringNotContainsString($client->public_id, $status->json('invite_url'));

        $response = $this->actingAs($client)->postJson(route('radio.session'), [
            'channel_public_id' => $channel->public_id,
            'room_id' => 'arka01-'.str_repeat('f', 64),
        ])->assertOk();

        [$payload, $signature] = explode('.', $response->json('token'));
        $expectedSignature = $this->base64UrlEncode(hash_hmac('sha256', $payload, $this->secret, true));
        $claims = json_decode($this->base64UrlDecode($payload), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($expectedSignature, $signature);
        $this->assertSame($client->public_id, $claims['sub']);
        $this->assertSame($expectedRoom, $claims['room']);
    }

    public function test_family_member_can_join_and_listen_only_while_owner_channel_is_active(): void
    {
        $owner = User::factory()->create(['name' => 'Laura']);
        $relative = User::factory()->create(['name' => 'Rosa']);
        $channel = $this->channelFor($owner);

        $this->actingAs($relative)->post(route('radio.invitation.join', $channel->share_code))->assertRedirect();
        $this->assertDatabaseHas('radio_channel_members', ['radio_channel_id' => $channel->id, 'user_id' => $relative->id]);
        $this->actingAs($relative)->getJson(route('radio.status'))->assertExactJson(['enabled' => false]);

        RideRequest::factory()->create([
            'client_user_id' => $owner->id,
            'is_scheduled' => false,
            'status' => 'pending',
        ]);

        $ownerStatus = $this->actingAs($owner)->getJson(route('radio.status'))->assertJson(['enabled' => true]);
        $relativeStatus = $this->actingAs($relative)->getJson(route('radio.status'))
            ->assertJson(['enabled' => true, 'is_owner' => false, 'owner' => ['name' => 'Laura']]);
        $this->assertSame($ownerStatus->json('room_id'), $relativeStatus->json('room_id'));

        RideRequest::query()->where('client_user_id', $owner->id)->update(['status' => 'cancelled']);
        $this->actingAs($relative)->getJson(route('radio.status'))->assertExactJson(['enabled' => false]);
    }

    public function test_driver_uses_their_own_circle_after_accepting_not_the_clients_channel(): void
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $request = RideRequest::factory()->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'accepted',
        ]);
        Ride::factory()->create([
            'ride_request_id' => $request->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'in_progress',
        ]);

        $clientRoom = $this->actingAs($client)->getJson(route('radio.status'))->assertJson(['is_owner' => true])->json('room_id');
        $driverRoom = $this->actingAs($driver)->getJson(route('radio.status'))->assertJson(['is_owner' => true])->json('room_id');
        $this->assertNotSame($clientRoom, $driverRoom);
    }

    public function test_user_cannot_request_a_channel_they_have_not_joined(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $channel = $this->channelFor($owner);
        RideRequest::factory()->create(['client_user_id' => $owner->id, 'status' => 'pending', 'is_scheduled' => false]);

        $this->actingAs($stranger)->postJson(route('radio.session'), [
            'channel_public_id' => $channel->public_id,
        ])->assertConflict();
    }

    public function test_scheduled_channels_appear_only_after_the_ride_starts_and_hide_when_it_ends(): void
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $request = RideRequest::factory()->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'is_scheduled' => true,
            'status' => 'accepted',
        ]);
        $ride = Ride::factory()->create([
            'ride_request_id' => $request->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'scheduled',
            'started_at' => null,
        ]);

        $this->actingAs($client)->getJson(route('radio.status'))->assertExactJson(['enabled' => false]);
        $ride->update(['status' => 'in_progress', 'started_at' => now()]);
        $this->actingAs($client)->getJson(route('radio.status'))->assertJson(['enabled' => true]);
        $ride->update(['status' => 'completed', 'completed_at' => now()]);
        $this->actingAs($client)->getJson(route('radio.status'))->assertExactJson(['enabled' => false]);
    }

    public function test_owner_can_remove_members_and_rotate_the_shared_link(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $channel = $this->channelFor($owner);
        $channel->members()->create(['user_id' => $member->id, 'joined_at' => now()]);
        $oldCode = $channel->share_code;

        $this->actingAs($owner)->delete(route('radio.channels.members.destroy', [$channel->public_id, $member->public_id]))->assertRedirect();
        $this->assertDatabaseMissing('radio_channel_members', ['radio_channel_id' => $channel->id, 'user_id' => $member->id]);

        $this->actingAs($owner)->post(route('radio.channels.rotate-invitation', $channel->public_id))->assertRedirect();
        $this->assertNotSame($oldCode, $channel->fresh()->share_code);
        $this->get(route('radio.invitation.show', $oldCode))->assertNotFound();
    }

    public function test_admin_and_cooperative_accounts_are_forbidden(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $cooperativeUser = User::factory()->create();
        Cooperative::query()->create(['user_id' => $cooperativeUser->id, 'name' => 'Cooperativa Radio']);

        $this->actingAs($admin)->getJson(route('radio.status'))->assertForbidden();
        $this->actingAs($cooperativeUser)->getJson(route('radio.status'))->assertForbidden();
    }

    private function channelFor(User $owner): RadioChannel
    {
        return RadioChannel::query()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Canal de '.$owner->name,
        ]);
    }

    private function roomFor(RadioChannel $channel): string
    {
        return 'arka01-'.hash_hmac('sha256', 'radio-channel:'.$channel->public_id, $this->secret);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/'));
    }
}
