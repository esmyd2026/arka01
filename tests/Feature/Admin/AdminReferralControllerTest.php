<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Trazabilidad de referidos (pedido explícito del usuario): quién invitó a
 * quién a registrarse — ver App\Models\User::referredBy()/referrals().
 */
class AdminReferralControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_regular_user_cannot_access_the_referrals_panel(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.referrals.index'))->assertForbidden();
    }

    public function test_the_list_only_includes_accounts_with_a_referrer(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $referrer = User::factory()->create(['name' => 'María Quien Invita']);
        $referred = User::factory()->create(['name' => 'Pedro Referido', 'referred_by_user_id' => $referrer->id]);
        User::factory()->create(['name' => 'Nadie lo Invitó']);

        $response = $this->actingAs($admin)->get(route('admin.referrals.index'));

        $response->assertInertia(fn ($page) => $page
            ->has('referrals.data', 1)
            ->where('referrals.data.0.id', $referred->id)
            ->where('referrals.data.0.referred_by.id', $referrer->id)
        );
    }

    public function test_the_list_can_be_filtered_by_name(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $referrer = User::factory()->create();
        $match = User::factory()->create(['name' => 'Carlos Andrade', 'referred_by_user_id' => $referrer->id]);
        User::factory()->create(['name' => 'Pedro Salazar', 'referred_by_user_id' => $referrer->id]);

        $response = $this->actingAs($admin)->get(route('admin.referrals.index', ['q' => 'Andrade']));

        $response->assertInertia(fn ($page) => $page
            ->has('referrals.data', 1)
            ->where('referrals.data.0.id', $match->id)
        );
    }
}
