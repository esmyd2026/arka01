<?php

namespace Tests\Feature\Cooperative;

use App\Models\ClientCooperative;
use App\Models\Cooperative;
use App\Models\CooperativeDriverMembership;
use App\Models\CooperativeWalletEntry;
use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pedido explícito del usuario: la cooperativa cobra su propia tarifa al
 * cliente y define cuánto le paga a sus conductores — la diferencia es su
 * margen. Como el dinero físico de una carrera en efectivo lo recibe el
 * conductor y el de una transferencia lo recibe la cooperativa, se lleva una
 * "billetera" (App\Models\CooperativeWalletEntry) que compensa ambos casos.
 * Ejemplo exacto dado por el usuario: cobra $0.50/km, paga $0.30/km (60% del
 * precio final) — una carrera de $10 en efectivo deja al conductor debiendo
 * $4 a la cooperativa; una de $10 por transferencia deja a la cooperativa
 * debiendo $6 al conductor.
 */
class CooperativeWalletTest extends TestCase
{
    use RefreshDatabase;

    private function cooperativeWithRates(?float $rate, ?float $pay): Cooperative
    {
        $cooperativeUser = User::factory()->create();
        $cooperative = Cooperative::query()->create([
            'user_id' => $cooperativeUser->id,
            'name' => 'Coop Test',
            'rate_per_km' => $rate,
            'driver_pay_rate_per_km' => $pay,
        ]);
        $cooperative->forceFill(['status' => 'approved'])->save();

        return $cooperative;
    }

    private function rideFor(Cooperative $cooperative, User $driver, string $paymentMethod, float $price): Ride
    {
        $client = User::factory()->create();
        $rideRequest = RideRequest::factory()->create([
            'client_user_id' => $client->id,
            'cooperative_id' => $cooperative->id,
            'driver_user_id' => $driver->id,
            'status' => 'accepted',
        ]);

        return Ride::factory()->create([
            'ride_request_id' => $rideRequest->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'payment_method' => $paymentMethod,
            'price' => $price,
            'stops_price' => null,
            'status' => 'in_progress',
        ]);
    }

    public function test_completing_a_cash_ride_makes_the_driver_owe_the_cooperative(): void
    {
        $cooperative = $this->cooperativeWithRates(0.50, 0.30);
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $ride = $this->rideFor($cooperative, $driver, 'efectivo', 10.0);

        $this->actingAs($driver)->post(route('rides.complete', $ride))->assertRedirect();

        $this->assertDatabaseHas('cooperative_wallet_entries', [
            'cooperative_id' => $cooperative->id,
            'driver_user_id' => $driver->id,
            'ride_id' => $ride->id,
            'direction' => 'driver_owes_cooperative',
            'amount' => '4.00',
        ]);
    }

    public function test_completing_a_transfer_ride_makes_the_cooperative_owe_the_driver(): void
    {
        $cooperative = $this->cooperativeWithRates(0.50, 0.30);
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $ride = $this->rideFor($cooperative, $driver, 'transferencia', 10.0);

        $this->actingAs($driver)->post(route('rides.complete', $ride))->assertRedirect();

        $this->assertDatabaseHas('cooperative_wallet_entries', [
            'cooperative_id' => $cooperative->id,
            'driver_user_id' => $driver->id,
            'ride_id' => $ride->id,
            'direction' => 'cooperative_owes_driver',
            'amount' => '6.00',
        ]);
    }

    /**
     * Pedido explícito del usuario: "las que sean por transferencia... se
     * descuentan de las que le dieron en efectivo al conductor" — el saldo
     * neto compensa ambos tipos de movimiento.
     */
    public function test_the_balance_nets_cash_and_transfer_entries(): void
    {
        $cooperative = $this->cooperativeWithRates(0.50, 0.30);
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $cashRide = $this->rideFor($cooperative, $driver, 'efectivo', 10.0);
        $this->actingAs($driver)->post(route('rides.complete', $cashRide))->assertRedirect();

        $transferRide = $this->rideFor($cooperative, $driver, 'transferencia', 10.0);
        $this->actingAs($driver)->post(route('rides.complete', $transferRide))->assertRedirect();

        // $4 que debía el conductor - $6 que le debía la cooperativa = -$2:
        // la cooperativa termina debiéndole $2 al conductor en total.
        $this->assertEquals(-2.0, CooperativeWalletEntry::balanceFor($cooperative->id, $driver->id));
    }

    public function test_no_wallet_entry_is_created_when_the_cooperative_has_not_configured_its_rates(): void
    {
        $cooperative = $this->cooperativeWithRates(null, null);
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $ride = $this->rideFor($cooperative, $driver, 'efectivo', 10.0);

        $this->actingAs($driver)->post(route('rides.complete', $ride))->assertRedirect();

        $this->assertDatabaseCount('cooperative_wallet_entries', 0);
    }

    /**
     * Pedido explícito del usuario: la cooperativa define su propia tarifa
     * al cliente — reemplaza el promedio de tarifas de sus conductores
     * miembros mientras esté configurada.
     */
    public function test_a_ride_request_uses_the_cooperatives_own_rate_when_configured(): void
    {
        $cooperative = $this->cooperativeWithRates(0.75, 0.40);
        $client = User::factory()->create();
        ClientCooperative::query()->create(['client_user_id' => $client->id, 'cooperative_id' => $cooperative->id]);

        $driver = User::factory()->create();
        DriverProfile::factory()->create([
            'user_id' => $driver->id,
            'driver_type' => 'public_transport',
            'current_lat' => -2.1700,
            'current_lng' => -79.9000,
            // Muy distinta a la de la cooperativa a propósito, para
            // confirmar que NO se usa el promedio de conductores acá.
            'rate_per_km' => 0.10,
        ]);
        CooperativeDriverMembership::query()->create([
            'cooperative_id' => $cooperative->id,
            'driver_user_id' => $driver->id,
            'invited_by_user_id' => $cooperative->user_id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);
        $plan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'plus')->firstOrFail();
        Subscription::factory()->for($driver)->create(['subscription_plan_id' => $plan->id, 'status' => 'active']);

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'cooperative_id' => $cooperative->id,
            'origin_lat' => -2.1701,
            'origin_lng' => -79.9001,
            'destination_lat' => -2.1800,
            'destination_lng' => -79.9100,
        ])->assertRedirect();

        $rideRequest = RideRequest::query()->latest('id')->firstOrFail();
        // El precio tiene que reflejar la tarifa de la cooperativa (0.75),
        // nunca la del conductor (0.10) — comparado contra la distancia real
        // guardada, con margen de tolerancia por el margen fijo del cálculo.
        $this->assertGreaterThan((float) $rideRequest->distance_km * 0.5, (float) $rideRequest->current_offered_price);
    }

    /**
     * Rango de cobertura (pedido explícito del usuario): la cooperativa
     * configura hasta qué distancia de su base acepta solicitudes.
     */
    public function test_a_cooperative_out_of_its_own_coverage_range_is_not_offered_to_the_client(): void
    {
        $cooperativeUser = User::factory()->create();
        $cooperative = Cooperative::query()->create([
            'user_id' => $cooperativeUser->id,
            'name' => 'Coop Lejana',
            'stand_lat' => -2.1700,
            'stand_lng' => -79.9000,
            'max_request_distance_km' => 5,
        ]);
        $cooperative->forceFill(['status' => 'approved'])->save();

        $client = User::factory()->create();
        ClientCooperative::query()->create(['client_user_id' => $client->id, 'cooperative_id' => $cooperative->id]);

        // Origen a más de 5 km de la base de la cooperativa.
        $response = $this->actingAs($client)->get(route('ride-requests.create', [
            'origin_lat' => -2.3000,
            'origin_lng' => -79.9000,
        ]));

        $response->assertInertia(fn ($page) => $page->has('cooperatives', 0));
    }

    public function test_a_cooperative_within_its_coverage_range_is_still_offered(): void
    {
        $cooperativeUser = User::factory()->create();
        $cooperative = Cooperative::query()->create([
            'user_id' => $cooperativeUser->id,
            'name' => 'Coop Cercana',
            'stand_lat' => -2.1700,
            'stand_lng' => -79.9000,
            'max_request_distance_km' => 50,
        ]);
        $cooperative->forceFill(['status' => 'approved'])->save();

        $client = User::factory()->create();
        ClientCooperative::query()->create(['client_user_id' => $client->id, 'cooperative_id' => $cooperative->id]);

        $response = $this->actingAs($client)->get(route('ride-requests.create', [
            'origin_lat' => -2.1701,
            'origin_lng' => -79.9001,
        ]));

        $response->assertInertia(fn ($page) => $page->has('cooperatives', 1));
    }

    public function test_the_driver_pay_rate_cannot_exceed_the_charged_rate(): void
    {
        $cooperativeUser = User::factory()->create();
        $cooperative = Cooperative::query()->create(['user_id' => $cooperativeUser->id, 'name' => 'Coop Validación']);

        $this->actingAs($cooperativeUser)->post(route('cooperative.profile.update'), [
            'rate_per_km' => 0.50,
            'driver_pay_rate_per_km' => 0.80,
            'declared_driver_count' => 0,
            'declared_unit_count' => 0,
            'response_timeout_seconds' => 30,
            'automatic_assignment_enabled' => true,
            'manual_assignment_timeout_seconds' => 30,
        ])->assertSessionHasErrors('driver_pay_rate_per_km');

        $this->assertNull($cooperative->fresh()->rate_per_km);
    }
}
