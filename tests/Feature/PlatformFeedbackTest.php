<?php

namespace Tests\Feature;

use App\Models\PlatformFeedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Ayúdanos a mejorar ARKA01" (roadmap de mejoras, sección 14) — formulario
 * público en el Home, sin necesidad de sesión.
 */
class PlatformFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_can_submit_feedback_without_a_name_or_email(): void
    {
        $response = $this->post(route('platform-feedback.store'), [
            'type' => 'sugerencia',
            'comment' => 'Me encantaría poder elegir el color del auto.',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('platform_feedback', [
            'name' => null,
            'email' => null,
            'type' => 'sugerencia',
            'comment' => 'Me encantaría poder elegir el color del auto.',
            'status' => 'nueva',
        ]);
    }

    public function test_the_comment_is_required(): void
    {
        $this->post(route('platform-feedback.store'), ['type' => 'sugerencia'])
            ->assertSessionHasErrors('comment');
    }

    public function test_a_regular_user_cannot_access_the_admin_panel(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.platform-feedback.index'))->assertForbidden();
    }

    public function test_an_admin_can_classify_feedback_with_an_internal_note(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $item = PlatformFeedback::query()->create(['type' => 'sugerencia', 'comment' => 'X']);

        $this->actingAs($admin)->patch(route('admin.platform-feedback.update', $item), [
            'status' => 'considerada',
            'internal_notes' => 'Buena idea para el roadmap.',
        ])->assertRedirect();

        $this->assertSame('considerada', $item->fresh()->status);
        $this->assertSame('Buena idea para el roadmap.', $item->fresh()->internal_notes);
    }
}
