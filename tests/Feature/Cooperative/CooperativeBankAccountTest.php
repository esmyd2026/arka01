<?php

namespace Tests\Feature\Cooperative;

use App\Models\Cooperative;
use App\Models\CooperativeBankAccount;
use App\Models\Fleet;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\User;
use App\Notifications\CooperativeTransferPaymentNotified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CooperativeBankAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_cooperative_can_register_a_bank_account_from_its_profile(): void
    {
        $owner = User::factory()->create();
        $cooperative = Cooperative::query()->create(['user_id' => $owner->id, 'name' => 'Viaje Seguro', 'ruc' => '0999999999001']);
        $cooperative->forceFill(['status' => 'approved'])->save();

        $this->actingAs($owner)->post(route('cooperative.bank-accounts.store'), [
            'account_holder_name' => 'Viaje Seguro S.A.',
            'identity_number' => '0999999999001',
            'bank_name' => 'Banco Guayaquil',
            'account_type' => 'corriente',
            'account_number' => '1234567890',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('cooperative_bank_accounts', [
            'cooperative_id' => $cooperative->id,
            'account_number' => '1234567890',
            'is_favorite' => true,
        ]);
    }

    public function test_the_client_sees_the_cooperative_account_and_can_notify_the_transfer(): void
    {
        Notification::fake();
        [$owner, $client, $ride, $account] = $this->transferRide();

        $this->actingAs($client)->get(route('rides.show', $ride))
            ->assertInertia(fn (Assert $page) => $page
                ->where('transferGoesToCooperative', true)
                ->where('transferRecipient', 'Viaje Seguro')
                ->has('transferAccounts', 1)
                ->where('transferAccounts.0.account_number', $account->account_number)
                ->has('driverBankAccounts', 0));

        $this->actingAs($client)->post(route('rides.transfer-payment.notify', $ride))
            ->assertSessionHasNoErrors();

        $this->assertNotNull($ride->fresh()->transfer_payment_notified_at);
        Notification::assertSentTo($owner, CooperativeTransferPaymentNotified::class);
    }

    public function test_a_driver_cannot_notify_a_clients_transfer(): void
    {
        [, , $ride] = $this->transferRide();

        $this->actingAs($ride->driver)->post(route('rides.transfer-payment.notify', $ride))->assertForbidden();
    }

    /** @return array{User, User, Ride, CooperativeBankAccount} */
    private function transferRide(): array
    {
        $owner = User::factory()->create();
        $client = User::factory()->create();
        $driver = User::factory()->create();
        $cooperative = Cooperative::query()->create(['user_id' => $owner->id, 'name' => 'Viaje Seguro']);
        $cooperative->forceFill(['status' => 'approved'])->save();
        $account = CooperativeBankAccount::query()->create([
            'cooperative_id' => $cooperative->id,
            'account_holder_name' => 'Viaje Seguro S.A.',
            'identity_number' => '0999999999001',
            'bank_name' => 'Banco Guayaquil',
            'account_type' => 'corriente',
            'account_number' => '1234567890',
            'is_favorite' => true,
        ]);
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $request = RideRequest::factory()->create([
            'fleet_id' => $fleet->id,
            'cooperative_id' => $cooperative->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'accepted',
        ]);
        $ride = Ride::factory()->create([
            'ride_request_id' => $request->id,
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'payment_method' => 'transferencia',
            'status' => 'in_progress',
            'picked_up_at' => now(),
            'price' => 10,
        ]);

        return [$owner, $client, $ride, $account];
    }
}
