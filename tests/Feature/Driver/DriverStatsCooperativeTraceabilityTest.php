<?php

namespace Tests\Feature\Driver;

use App\Models\Cooperative;
use App\Models\CooperativeDriverMembership;
use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pedido explícito del usuario: "el conductor debería tener la trazabilidad
 * de las carreras de cooperativas en sus indicadores" — Driver/Stats.vue
 * ("Mis indicadores") ahora expone un desglose por carrera con las mismas
 * columnas que ya ve la cooperativa del otro lado (Cooperative/Wallet.vue),
 * enlazado desde el mensaje de la billetera.
 */
class DriverStatsCooperativeTraceabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_stats_screen_exposes_the_cooperative_ride_breakdown(): void
    {
        $cooperativeUser = User::factory()->create();
        $cooperative = Cooperative::query()->create([
            'user_id' => $cooperativeUser->id,
            'name' => 'Coop Amazonas',
            'rate_per_km' => 0.50,
            'driver_pay_rate_per_km' => 0.30,
        ]);
        $cooperative->forceFill(['status' => 'approved'])->save();

        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        CooperativeDriverMembership::query()->create([
            'cooperative_id' => $cooperative->id,
            'driver_user_id' => $driver->id,
            'invited_by_user_id' => $cooperativeUser->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);

        $client = User::factory()->create();
        $rideRequest = RideRequest::factory()->create([
            'client_user_id' => $client->id,
            'cooperative_id' => $cooperative->id,
            'driver_user_id' => $driver->id,
            'status' => 'accepted',
        ]);
        $ride = Ride::factory()->create([
            'ride_request_id' => $rideRequest->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'payment_method' => 'efectivo',
            'price' => 10.0,
            'stops_price' => null,
            'status' => 'in_progress',
        ]);

        $this->actingAs($driver)->post(route('rides.complete', $ride))->assertRedirect();

        $this->actingAs($driver)->get(route('rides.stats'))
            ->assertInertia(fn ($page) => $page
                ->where('cooperativeWallet.cooperative_name', 'Coop Amazonas')
                ->where('cooperativeRideHistory.data.0.client', $client->name)
                ->where('cooperativeRideHistory.data.0.payment_method', 'efectivo')
                ->where('cooperativeRideHistory.data.0.price', 10)
                // El conductor cobró los $10 en efectivo, pero solo le
                // correspondían $6 (60%, ratio 0.30/0.50) — debe $4.
                ->where('cooperativeRideHistory.data.0.driver_pay', 6)
                ->where('cooperativeRideHistory.data.0.driver_owes', 4)
            );
    }

    public function test_an_independent_driver_gets_no_cooperative_ride_history(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $this->actingAs($driver)->get(route('rides.stats'))
            ->assertInertia(fn ($page) => $page->where('cooperativeRideHistory', null));
    }
}
