<?php

namespace Tests\Feature\Api\V1;

use App\Models\DriverBankAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cuentas bancarias del conductor desde la app móvil (roadmap Hito 5B,
 * paridad con la web) — mismas reglas que DriverBankAccountController (web).
 */
class MobileDriverBankAccountTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_a_driver_can_list_their_bank_accounts(): void
    {
        $driver = User::factory()->create();
        DriverBankAccount::factory()->for($driver, 'driver')->create(['bank_name' => 'Banco Pichincha']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->getJson('/api/v1/driver/bank-accounts')
            ->assertOk()
            ->assertJsonCount(1, 'bank_accounts')
            ->assertJsonPath('bank_accounts.0.bank_name', 'Banco Pichincha');
    }

    public function test_a_driver_can_add_a_bank_account_and_it_becomes_favorite_automatically(): void
    {
        $driver = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->postJson('/api/v1/driver/bank-accounts', [
                'identity_number' => '0912345678',
                'bank_name' => 'Banco Pichincha',
                'account_type' => 'ahorros',
                'account_number' => '1234567890',
            ]);

        $response->assertCreated()->assertJsonPath('bank_account.is_favorite', true);

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

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->postJson('/api/v1/driver/bank-accounts', [
                'identity_number' => '0912345678',
                'bank_name' => 'Banco Guayaquil',
                'account_type' => 'corriente',
                'account_number' => '9876543210',
            ])->assertCreated();

        $this->assertDatabaseHas('driver_bank_accounts', ['bank_name' => 'Banco Guayaquil', 'is_favorite' => false]);
        $this->assertSame(1, DriverBankAccount::where('user_id', $driver->id)->where('is_favorite', true)->count());
    }

    public function test_marking_an_account_favorite_unmarks_the_others(): void
    {
        $driver = User::factory()->create();
        $first = DriverBankAccount::factory()->for($driver, 'driver')->create(['is_favorite' => true]);
        $second = DriverBankAccount::factory()->for($driver, 'driver')->create(['is_favorite' => false]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->patchJson("/api/v1/driver/bank-accounts/{$second->id}/favorite")
            ->assertOk();

        $this->assertFalse($first->fresh()->is_favorite);
        $this->assertTrue($second->fresh()->is_favorite);
    }

    public function test_deleting_the_favorite_account_promotes_another_one(): void
    {
        $driver = User::factory()->create();
        $favorite = DriverBankAccount::factory()->for($driver, 'driver')->create(['is_favorite' => true]);
        $other = DriverBankAccount::factory()->for($driver, 'driver')->create(['is_favorite' => false]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->deleteJson("/api/v1/driver/bank-accounts/{$favorite->id}")
            ->assertOk();

        $this->assertTrue($other->fresh()->is_favorite);
    }

    public function test_a_driver_cannot_modify_another_drivers_account(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $account = DriverBankAccount::factory()->for($owner, 'driver')->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->deleteJson("/api/v1/driver/bank-accounts/{$account->id}")
            ->assertForbidden();
    }

    public function test_the_identity_number_must_be_ten_digits(): void
    {
        $driver = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->postJson('/api/v1/driver/bank-accounts', [
                'identity_number' => '123',
                'bank_name' => 'Banco Pichincha',
                'account_type' => 'ahorros',
                'account_number' => '1234567890',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('identity_number');
    }

    public function test_it_requires_a_token(): void
    {
        $this->getJson('/api/v1/driver/bank-accounts')->assertUnauthorized();
    }
}
