<?php

namespace Tests\Feature\Admin;

use App\Models\RatingReason;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mantenimiento del catálogo de "Motivos de Calificación" (pedido explícito
 * del usuario): agregar, editar, activar/desactivar — sembrado inicial en la
 * propia migración (ver create_rating_reasons_table), igual que subscription_plans.
 */
class RatingReasonTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_two_catalogs_come_seeded_from_the_migration(): void
    {
        $this->assertSame(15, RatingReason::query()->where('direction', 'client_to_driver')->count());
        $this->assertSame(13, RatingReason::query()->where('direction', 'driver_to_client')->count());
    }

    public function test_a_regular_user_cannot_manage_rating_reasons(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.rating-reasons.index'))->assertForbidden();
    }

    public function test_an_admin_can_create_a_new_reason(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.rating-reasons.store'), [
            'direction' => 'client_to_driver',
            'text' => 'Cobró de más al final del viaje.',
        ])->assertRedirect();

        $this->assertDatabaseHas('rating_reasons', ['text' => 'Cobró de más al final del viaje.', 'direction' => 'client_to_driver']);
    }

    public function test_an_admin_can_deactivate_a_reason(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $reason = RatingReason::factory()->create(['is_active' => true]);

        $this->actingAs($admin)->patch(route('admin.rating-reasons.update', $reason), [
            'direction' => $reason->direction,
            'text' => $reason->text,
            'is_active' => false,
        ])->assertRedirect();

        $this->assertFalse($reason->fresh()->is_active);
    }
}
