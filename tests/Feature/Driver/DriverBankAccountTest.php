<?php

namespace Tests\Feature\Driver;

use App\Models\DriverBankAccount;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Pedido explícito del usuario: el conductor declara varias cuentas
 * bancarias en su perfil (cédula, banco, tipo de cuenta, número de cuenta) y
 * marca una como favorita — el cliente las ve (la favorita primero) cuando
 * la carrera es por transferencia y el conductor va en camino a recogerlo.
 */
class DriverBankAccountTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Pedido explícito del usuario: "que sea un seleccionable... con los
     * principales primero" — la pantalla de perfil recibe el catálogo
     * completo, con los bancos de mayor tamaño/uso real primero.
     */
    public function test_the_profile_screen_exposes_the_bank_catalog_with_the_main_ones_first(): void
    {
        $driver = User::factory()->create();

        $this->actingAs($driver)->get(route('driver.profile.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('banks.0', 'Banco Pichincha')
                ->where('banks.1', 'Banco Guayaquil')
            );
    }

    public function test_a_driver_can_add_a_bank_account_and_it_becomes_favorite_automatically(): void
    {
        $driver = User::factory()->create();

        $this->actingAs($driver)->post(route('driver.bank-accounts.store'), [
            'identity_number' => '0912345678',
            'bank_name' => 'Banco Pichincha',
            'account_type' => 'ahorros',
            'account_number' => '1234567890',
        ])->assertRedirect();

        $this->assertDatabaseHas('driver_bank_accounts', [
            'user_id' => $driver->id,
            'bank_name' => 'Banco Pichincha',
            'is_favorite' => true,
        ]);
    }

    public function test_a_second_account_is_not_favorite_unless_marked(): void
    {
        $driver = User::factory()->create();
        DriverBankAccount::factory()->for($driver, 'driver')->create(['is_favorite' => true]);

        $this->actingAs($driver)->post(route('driver.bank-accounts.store'), [
            'identity_number' => '0912345678',
            'bank_name' => 'Banco Guayaquil',
            'account_type' => 'corriente',
            'account_number' => '9876543210',
        ])->assertRedirect();

        $this->assertDatabaseHas('driver_bank_accounts', ['bank_name' => 'Banco Guayaquil', 'is_favorite' => false]);
        $this->assertSame(1, DriverBankAccount::where('user_id', $driver->id)->where('is_favorite', true)->count());
    }

    public function test_marking_an_account_favorite_unmarks_the_others(): void
    {
        $driver = User::factory()->create();
        $first = DriverBankAccount::factory()->for($driver, 'driver')->create(['is_favorite' => true]);
        $second = DriverBankAccount::factory()->for($driver, 'driver')->create(['is_favorite' => false]);

        $this->actingAs($driver)->patch(route('driver.bank-accounts.favorite', $second))->assertRedirect();

        $this->assertFalse($first->fresh()->is_favorite);
        $this->assertTrue($second->fresh()->is_favorite);
    }

    /**
     * Bug evitado a propósito: si se borra la favorita y quedan otras,
     * ninguna quedaría marcada sin este comportamiento.
     */
    public function test_deleting_the_favorite_account_promotes_another_one(): void
    {
        $driver = User::factory()->create();
        $favorite = DriverBankAccount::factory()->for($driver, 'driver')->create(['is_favorite' => true]);
        $other = DriverBankAccount::factory()->for($driver, 'driver')->create(['is_favorite' => false]);

        $this->actingAs($driver)->delete(route('driver.bank-accounts.destroy', $favorite))->assertRedirect();

        $this->assertTrue($other->fresh()->is_favorite);
    }

    public function test_a_driver_cannot_modify_another_drivers_account(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $account = DriverBankAccount::factory()->for($owner, 'driver')->create();

        $this->actingAs($stranger)->delete(route('driver.bank-accounts.destroy', $account))->assertForbidden();
        $this->actingAs($stranger)->patch(route('driver.bank-accounts.favorite', $account))->assertForbidden();
    }

    public function test_the_identity_number_must_be_ten_digits(): void
    {
        $driver = User::factory()->create();

        $this->actingAs($driver)->post(route('driver.bank-accounts.store'), [
            'identity_number' => '123',
            'bank_name' => 'Banco Pichincha',
            'account_type' => 'ahorros',
            'account_number' => '1234567890',
        ])->assertSessionHasErrors('identity_number');
    }

    /**
     * Pedido explícito del usuario: el cliente las ve (la favorita primero)
     * cuando la carrera es por transferencia y el conductor va en camino a
     * recogerlo — nunca la cédula completa.
     */
    public function test_the_client_sees_the_driver_accounts_on_a_transfer_ride(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();
        DriverBankAccount::factory()->for($driver, 'driver')->create([
            'identity_number' => '0912345678',
            'bank_name' => 'Banco No Favorito',
            'is_favorite' => false,
        ]);
        $favorite = DriverBankAccount::factory()->for($driver, 'driver')->create([
            'identity_number' => '0987654321',
            'bank_name' => 'Banco Favorito',
            'is_favorite' => true,
        ]);

        $ride = Ride::factory()->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'payment_method' => 'transferencia',
            'status' => 'in_progress',
        ]);

        $this->actingAs($client)->get(route('rides.show', $ride))
            ->assertInertia(fn (Assert $page) => $page
                ->has('driverBankAccounts', 2)
                ->where('driverBankAccounts.0.bank_name', 'Banco Favorito')
                ->where('driverBankAccounts.0.is_favorite', true)
                ->where('driverBankAccounts.0.masked_identity_number', 'xxxxxxx321')
                ->missing('driverBankAccounts.0.identity_number')
            );
    }

    public function test_the_client_does_not_see_bank_accounts_on_a_cash_ride(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();
        DriverBankAccount::factory()->for($driver, 'driver')->create(['is_favorite' => true]);

        $ride = Ride::factory()->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'payment_method' => 'efectivo',
            'status' => 'in_progress',
        ]);

        $this->actingAs($client)->get(route('rides.show', $ride))
            ->assertInertia(fn (Assert $page) => $page->has('driverBankAccounts', 0));
    }

    public function test_the_driver_does_not_receive_their_own_bank_accounts_on_the_ride_screen(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();
        DriverBankAccount::factory()->for($driver, 'driver')->create(['is_favorite' => true]);

        $ride = Ride::factory()->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'payment_method' => 'transferencia',
            'status' => 'in_progress',
        ]);

        $this->actingAs($driver)->get(route('rides.show', $ride))
            ->assertInertia(fn (Assert $page) => $page->has('driverBankAccounts', 0));
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function clientWithFleetDriver(): array
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['rate_per_km' => 0.50, 'is_available' => true]);

        $fleet = Fleet::factory()->for($client, 'owner')->create();
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        return [$client, $driver];
    }
}
