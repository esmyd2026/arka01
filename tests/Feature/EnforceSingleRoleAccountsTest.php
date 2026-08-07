<?php

namespace Tests\Feature;

use App\Console\Commands\EnforceSingleRoleAccounts;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\Ride;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `php artisan app:enforce-single-role` (consideración agregada al alcance):
 * arreglo único de cuentas que quedaron con doble rol de cuando eso todavía
 * era posible (sección 3.1) — prioriza conductor y quita el lado cliente.
 */
class EnforceSingleRoleAccountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deletes_an_empty_fleet_and_keeps_the_driver_role(): void
    {
        $user = User::factory()->create();
        DriverProfile::factory()->for($user)->create();
        $fleet = Fleet::factory()->for($user, 'owner')->create();

        $this->artisan(EnforceSingleRoleAccounts::class)->assertSuccessful();

        $this->assertDatabaseMissing('fleets', ['id' => $fleet->id]);
        $this->assertDatabaseHas('driver_profiles', ['user_id' => $user->id]);
    }

    public function test_it_does_not_delete_a_fleet_that_has_real_ride_history(): void
    {
        $user = User::factory()->create();
        DriverProfile::factory()->for($user)->create();
        $fleet = Fleet::factory()->for($user, 'owner')->create();
        Ride::factory()->for($fleet)->create(['client_user_id' => $user->id]);

        $this->artisan(EnforceSingleRoleAccounts::class)->assertSuccessful();

        $this->assertDatabaseHas('fleets', ['id' => $fleet->id]);
        $this->assertDatabaseHas('driver_profiles', ['user_id' => $user->id]);
    }

    public function test_it_cancels_the_client_side_subscription_of_the_fixed_account(): void
    {
        $user = User::factory()->create();
        DriverProfile::factory()->for($user)->create();
        Fleet::factory()->for($user, 'owner')->create();

        $clientPlan = SubscriptionPlan::query()->where('owner_type', 'client')->where('code', 'plus')->firstOrFail();
        $subscription = Subscription::query()->create([
            'user_id' => $user->id,
            'subscription_plan_id' => $clientPlan->id,
            'status' => 'active',
            'started_at' => now(),
        ]);

        $this->artisan(EnforceSingleRoleAccounts::class)->assertSuccessful();

        $this->assertSame('expired', $subscription->fresh()->status);
    }

    public function test_it_leaves_single_role_accounts_untouched(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();

        $this->artisan(EnforceSingleRoleAccounts::class)->assertSuccessful();

        $this->assertDatabaseHas('driver_profiles', ['user_id' => $driver->id]);
        $this->assertDatabaseHas('fleets', ['id' => $fleet->id]);
    }
}
