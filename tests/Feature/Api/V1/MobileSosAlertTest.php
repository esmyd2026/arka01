<?php

namespace Tests\Feature\Api\V1;

use App\Mail\SosAlertMail;
use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\TrustedContact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Botón SOS desde la app móvil (roadmap Hito 5B, paridad con la web) —
 * reusa App\Services\Security\SosAlertSender, misma lógica que
 * SosAlertController (web).
 */
class MobileSosAlertTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_triggering_sos_emails_the_users_trusted_contacts_and_logs_the_alert(): void
    {
        Mail::fake();

        $client = User::factory()->create();
        TrustedContact::query()->create(['user_id' => $client->id, 'name' => 'Mamá', 'email' => 'mama@example.com']);
        TrustedContact::query()->create(['user_id' => $client->id, 'name' => 'Sin correo', 'phone' => '099']);

        $driver = User::factory()->create(['name' => 'Pedro Chofer']);
        DriverProfile::factory()->for($driver)->create(['vehicle_plate' => 'XYZ-987']);

        $ride = Ride::factory()->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'in_progress',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->postJson("/api/v1/rides/{$ride->id}/sos");

        $response->assertCreated()->assertJsonPath('notified_contacts_count', 1);

        $this->assertDatabaseHas('sos_alerts', [
            'ride_id' => $ride->id,
            'triggered_by' => $client->id,
            'driver_name' => 'Pedro Chofer',
            'vehicle_plate' => 'XYZ-987',
            'notified_contacts_count' => 1,
        ]);

        Mail::assertSent(SosAlertMail::class, 1);
    }

    public function test_sos_cannot_be_triggered_on_a_ride_that_is_not_in_progress(): void
    {
        $client = User::factory()->create();
        $ride = Ride::factory()->create(['client_user_id' => $client->id, 'status' => 'completed']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->postJson("/api/v1/rides/{$ride->id}/sos")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ride');
    }

    public function test_a_stranger_cannot_trigger_sos_on_someone_elses_ride(): void
    {
        $ride = Ride::factory()->create(['status' => 'in_progress']);
        $stranger = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->postJson("/api/v1/rides/{$ride->id}/sos")
            ->assertForbidden();
    }

    public function test_it_requires_a_token(): void
    {
        $ride = Ride::factory()->create(['status' => 'in_progress']);

        $this->postJson("/api/v1/rides/{$ride->id}/sos")->assertUnauthorized();
    }
}
