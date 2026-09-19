<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Recorrido guiado por rol desde la app móvil (roadmap Hito 5B, paridad
 * con la web).
 */
class MobileOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_completing_the_tour_marks_it_as_seen(): void
    {
        $user = User::factory()->create(['onboarding_completed_at' => null]);

        $this->withHeader('Authorization', 'Bearer '.$user->createToken('test')->plainTextToken)
            ->postJson('/api/v1/onboarding/complete')
            ->assertOk();

        $this->assertNotNull($user->fresh()->onboarding_completed_at);
    }

    public function test_it_requires_a_token(): void
    {
        $this->postJson('/api/v1/onboarding/complete')->assertUnauthorized();
    }
}
