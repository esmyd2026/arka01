<?php

namespace Tests\Feature\Admin;

use App\Models\Faq;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mantenimiento del catálogo de preguntas frecuentes (roadmap de mejoras,
 * sección 11) — administrable desde /admin/preguntas-frecuentes.
 */
class FaqTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_regular_user_cannot_access_the_admin_panel(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.faqs.index'))->assertForbidden();
    }

    public function test_an_admin_can_create_a_faq(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.faqs.store'), [
            'audience' => 'cliente',
            'category' => 'Carreras',
            'question' => '¿Cómo pido una carrera?',
            'answer' => 'Desde el Inicio, toque Pedir carrera.',
        ])->assertRedirect();

        $this->assertDatabaseHas('faqs', ['audience' => 'cliente', 'question' => '¿Cómo pido una carrera?']);
    }

    public function test_an_admin_can_deactivate_a_faq(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $faq = Faq::query()->create([
            'audience' => 'ambos', 'category' => 'General', 'question' => 'Q', 'answer' => 'A', 'is_active' => true,
        ]);

        $this->actingAs($admin)->patch(route('admin.faqs.update', $faq), [
            'audience' => 'ambos', 'category' => 'General', 'question' => 'Q', 'answer' => 'A', 'is_active' => false,
        ])->assertRedirect();

        $this->assertFalse($faq->fresh()->is_active);
    }

    public function test_an_admin_can_delete_a_faq(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $faq = Faq::query()->create(['audience' => 'ambos', 'category' => 'General', 'question' => 'Q', 'answer' => 'A']);

        $this->actingAs($admin)->delete(route('admin.faqs.destroy', $faq))->assertRedirect();

        $this->assertDatabaseMissing('faqs', ['id' => $faq->id]);
    }
}
