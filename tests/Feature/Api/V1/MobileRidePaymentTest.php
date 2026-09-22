<?php

namespace Tests\Feature\Api\V1;

use App\Models\Cooperative;
use App\Models\CooperativeBankAccount;
use App\Models\DriverBankAccount;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pago de una carrera desde la app móvil (pedido explícito del usuario:
 * "cierra todo el backend de pedir carrera... el tipo de pago si es
 * transferencia que aparezca las cuentas del conductor, si es efectivo, si
 * el conductor pertenece a una cooperativa"). Mismas reglas que la web
 * (App\Services\Ride\RidePaymentManager, extraído de RidePaymentController),
 * así que estos tests se enfocan en el contrato JSON y la autorización del
 * canal móvil, no en repetir la lógica de negocio ya cubierta en
 * tests/Feature/Ride/*.
 */
class MobileRidePaymentTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function cooperativeRide(array $overrides = []): Ride
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $cooperative = Cooperative::query()->create(['user_id' => User::factory()->create()->id, 'name' => 'Coop Central']);
        CooperativeBankAccount::query()->create([
            'cooperative_id' => $cooperative->id,
            'account_holder_name' => 'Coop Central',
            'identity_number' => '0987654321',
            'bank_name' => 'Banco Pichincha',
            'account_type' => 'corriente',
            'account_number' => '111222333',
            'is_favorite' => true,
        ]);

        $rideRequest = RideRequest::factory()->for($client, 'client')->for($driver, 'driver')->for($fleet)->create([
            'status' => 'accepted',
            'cooperative_id' => $cooperative->id,
        ]);

        return Ride::factory()->for($rideRequest)->for($fleet)->for($client, 'client')->for($driver, 'driver')
            ->create(array_merge(['status' => 'in_progress', 'picked_up_at' => now()], $overrides));
    }

    private function independentDriverRide(array $overrides = []): Ride
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        DriverBankAccount::factory()->for($driver, 'driver')->create(['bank_name' => 'Banco Guayaquil']);
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $rideRequest = RideRequest::factory()->for($client, 'client')->for($driver, 'driver')->for($fleet)->create(['status' => 'accepted']);

        return Ride::factory()->for($rideRequest)->for($fleet)->for($client, 'client')->for($driver, 'driver')
            ->create(array_merge(['status' => 'in_progress', 'picked_up_at' => now()], $overrides));
    }

    public function test_the_client_sees_the_cooperative_transfer_accounts(): void
    {
        $ride = $this->cooperativeRide(['payment_method' => 'transferencia']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($ride->client))
            ->getJson("/api/v1/rides/{$ride->id}");

        $response->assertOk()
            ->assertJsonPath('ride.cooperative.name', 'Coop Central')
            ->assertJsonPath('ride.transfer_accounts.0.bank_name', 'Banco Pichincha');
    }

    public function test_the_client_sees_the_independent_drivers_own_account(): void
    {
        $ride = $this->independentDriverRide(['payment_method' => 'transferencia']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($ride->client))
            ->getJson("/api/v1/rides/{$ride->id}");

        $response->assertOk()
            ->assertJsonPath('ride.cooperative', null)
            ->assertJsonPath('ride.transfer_accounts.0.bank_name', 'Banco Guayaquil');
    }

    public function test_the_driver_never_sees_transfer_accounts(): void
    {
        $ride = $this->cooperativeRide(['payment_method' => 'transferencia']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($ride->driver))
            ->getJson("/api/v1/rides/{$ride->id}");

        $response->assertOk()->assertJsonPath('ride.transfer_accounts', []);
    }

    public function test_the_client_can_upload_a_transfer_proof_for_a_cooperative_ride(): void
    {
        Storage::fake('local');
        $ride = $this->cooperativeRide(['status' => 'completed', 'payment_method' => 'transferencia', 'completed_at' => now()]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($ride->client))
            ->postJson("/api/v1/rides/{$ride->id}/payment-proof", [
                'payment_proof' => UploadedFile::fake()->image('comprobante.jpg'),
            ]);

        $response->assertOk()->assertJsonPath('ride.payment_status', 'proof_submitted');
        $this->assertNotNull($ride->fresh()->payment_proof_path);
    }

    public function test_uploading_a_proof_for_an_independent_driver_ride_is_rejected(): void
    {
        Storage::fake('local');
        $ride = $this->independentDriverRide(['status' => 'completed', 'payment_method' => 'transferencia', 'completed_at' => now()]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($ride->client))
            ->postJson("/api/v1/rides/{$ride->id}/payment-proof", [
                'payment_proof' => UploadedFile::fake()->image('comprobante.jpg'),
            ])
            ->assertUnprocessable();
    }

    public function test_a_stranger_cannot_upload_a_proof(): void
    {
        Storage::fake('local');
        $ride = $this->cooperativeRide(['status' => 'completed', 'payment_method' => 'transferencia', 'completed_at' => now()]);
        $stranger = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->postJson("/api/v1/rides/{$ride->id}/payment-proof", [
                'payment_proof' => UploadedFile::fake()->image('comprobante.jpg'),
            ])
            ->assertForbidden();
    }

    public function test_the_driver_can_confirm_cash_for_a_cooperative_ride(): void
    {
        $ride = $this->cooperativeRide(['status' => 'completed', 'payment_method' => 'efectivo', 'completed_at' => now()]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($ride->driver))
            ->postJson("/api/v1/rides/{$ride->id}/confirm-cash");

        $response->assertOk()->assertJsonPath('ride.payment_status', 'confirmed');
    }

    public function test_confirming_cash_for_an_independent_driver_ride_is_rejected(): void
    {
        $ride = $this->independentDriverRide(['status' => 'completed', 'payment_method' => 'efectivo', 'completed_at' => now()]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($ride->driver))
            ->postJson("/api/v1/rides/{$ride->id}/confirm-cash")
            ->assertUnprocessable();
    }

    public function test_the_client_cannot_confirm_cash(): void
    {
        $ride = $this->cooperativeRide(['status' => 'completed', 'payment_method' => 'efectivo', 'completed_at' => now()]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($ride->client))
            ->postJson("/api/v1/rides/{$ride->id}/confirm-cash")
            ->assertForbidden();
    }

    public function test_the_client_can_view_their_own_uploaded_proof(): void
    {
        Storage::fake('local');
        $ride = $this->cooperativeRide(['status' => 'completed', 'payment_method' => 'transferencia', 'completed_at' => now()]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($ride->client))
            ->postJson("/api/v1/rides/{$ride->id}/payment-proof", [
                'payment_proof' => UploadedFile::fake()->image('comprobante.jpg'),
            ])->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($ride->client))
            ->getJson("/api/v1/rides/{$ride->id}/payment-proof")
            ->assertOk();
    }

    public function test_a_stranger_cannot_view_the_proof(): void
    {
        Storage::fake('local');
        // Un solo token de Bearer por método de test: dos peticiones con
        // usuarios distintos en el mismo método autentican mal la segunda
        // (RequestGuard cachea el usuario resuelto en la instancia del
        // guard) — por eso el comprobante se deja puesto a mano en vez de
        // subirlo con el token del cliente antes de probar con el extraño.
        $ride = $this->cooperativeRide(['status' => 'completed', 'payment_method' => 'transferencia', 'completed_at' => now()]);
        $ride->forceFill(['payment_proof_path' => 'ride-payment-proofs/fake.jpg'])->save();
        $stranger = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->getJson("/api/v1/rides/{$ride->id}/payment-proof")
            ->assertForbidden();
    }
}
