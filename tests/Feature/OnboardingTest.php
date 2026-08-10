<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Recorrido guiado por rol, una sola vez (pedido explícito del usuario) — ver
 * Components/OnboardingTour.vue. El disparo automático y el contenido de los
 * pasos son de frontend puro; acá solo se prueba el flag del lado servidor.
 */
class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_user_has_not_completed_onboarding(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->onboarding_completed_at);
    }

    public function test_completing_onboarding_marks_the_timestamp(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('onboarding.complete'))->assertRedirect();

        $this->assertNotNull($user->fresh()->onboarding_completed_at);
    }

    public function test_a_guest_cannot_complete_onboarding(): void
    {
        $this->post(route('onboarding.complete'))->assertRedirect(route('login'));
    }
}
