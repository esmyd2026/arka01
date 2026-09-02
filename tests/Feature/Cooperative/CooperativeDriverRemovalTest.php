<?php

namespace Tests\Feature\Cooperative;

use App\Models\Cooperative;
use App\Models\CooperativeDriverMembership;
use App\Models\DriverProfile;
use App\Models\User;
use App\Notifications\CooperativeDriverRemovedPushNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Bug real encontrado al implementar el acceso cooperativa vs. independiente:
 * `driver_type` quedaba en 'public_transport' para siempre aunque la
 * membresía ya hubiera terminado — CooperativeDriverController::remove()
 * ahora lo revierte a 'independent'.
 */
class CooperativeDriverRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_removing_a_driver_reverts_their_driver_type_to_independent(): void
    {
        Notification::fake();

        $cooperativeUser = User::factory()->create();
        $cooperative = Cooperative::query()->create(['user_id' => $cooperativeUser->id, 'name' => 'Coop Central']);
        $cooperative->forceFill(['status' => 'approved'])->save();

        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['driver_type' => 'public_transport']);
        $membership = CooperativeDriverMembership::query()->create([
            'cooperative_id' => $cooperative->id,
            'driver_user_id' => $driver->id,
            'invited_by_user_id' => $cooperativeUser->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);

        $this->actingAs($cooperativeUser)
            ->delete(route('cooperative.drivers.remove', $membership))
            ->assertRedirect();

        $this->assertSame('independent', $driver->driverProfile->fresh()->driver_type);
        $this->assertSame('removed', $membership->fresh()->status);
    }

    /**
     * Pedido explícito del usuario: "¿y qué pasa cuando un conductor es
     * sacado de la cooperativa? ¿se actualiza eso para el conductor?" — el
     * acceso ya se resuelve solo, pero antes no había ningún aviso.
     */
    public function test_removing_a_driver_notifies_them(): void
    {
        Notification::fake();

        $cooperativeUser = User::factory()->create();
        $cooperative = Cooperative::query()->create(['user_id' => $cooperativeUser->id, 'name' => 'Coop Central']);
        $cooperative->forceFill(['status' => 'approved'])->save();

        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $membership = CooperativeDriverMembership::query()->create([
            'cooperative_id' => $cooperative->id,
            'driver_user_id' => $driver->id,
            'invited_by_user_id' => $cooperativeUser->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);

        $this->actingAs($cooperativeUser)
            ->delete(route('cooperative.drivers.remove', $membership))
            ->assertRedirect();

        Notification::assertSentTo($driver, CooperativeDriverRemovedPushNotification::class);
    }
}
