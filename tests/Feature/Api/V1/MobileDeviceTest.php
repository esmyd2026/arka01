<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Registro del token de notificaciones push nativas (roadmap app móvil,
 * groundwork del Hito 6) — el "dispositivo" ya es el propio token de
 * Sanctum, así que esto solo guarda push_token/push_provider en esa fila.
 */
class MobileDeviceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_register_their_push_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('android');

        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->putJson('/api/v1/device/push-token', [
                'push_token' => 'fcm-token-abc123',
                'push_provider' => 'fcm',
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $token->accessToken->id,
            'push_token' => 'fcm-token-abc123',
            'push_provider' => 'fcm',
        ]);
    }

    public function test_push_provider_must_be_a_known_value(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('android');

        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->putJson('/api/v1/device/push-token', [
                'push_token' => 'abc123',
                'push_provider' => 'onesignal',
            ])
            ->assertUnprocessable();
    }

    public function test_push_token_and_provider_are_required_together(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('android');

        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->putJson('/api/v1/device/push-token', ['push_token' => 'abc123'])
            ->assertUnprocessable();

        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->putJson('/api/v1/device/push-token', ['push_provider' => 'fcm'])
            ->assertUnprocessable();
    }

    public function test_a_user_can_clear_their_push_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('android');
        $token->accessToken->forceFill(['push_token' => 'old-token', 'push_provider' => 'fcm'])->save();

        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->putJson('/api/v1/device/push-token', [])
            ->assertOk();

        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $token->accessToken->id,
            'push_token' => null,
            'push_provider' => null,
        ]);
    }

    public function test_it_requires_a_token(): void
    {
        $this->putJson('/api/v1/device/push-token', ['push_token' => 'x', 'push_provider' => 'fcm'])
            ->assertUnauthorized();
    }
}
