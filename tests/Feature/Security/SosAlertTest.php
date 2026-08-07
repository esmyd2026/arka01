<?php

namespace Tests\Feature\Security;

use App\Mail\SosAlertMail;
use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\TrustedContact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Botón SOS (sección 8): visible durante un viaje en curso, avisa por correo
 * a los contactos de confianza de quien lo activa y deja un registro de la
 * emergencia con ubicación y datos del conductor/vehículo.
 */
class SosAlertTest extends TestCase
{
    use RefreshDatabase;

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

        $response = $this->actingAs($client)->post(route('sos.store', $ride));

        $response->assertRedirect();

        $this->assertDatabaseHas('sos_alerts', [
            'ride_id' => $ride->id,
            'triggered_by' => $client->id,
            'driver_name' => 'Pedro Chofer',
            'vehicle_plate' => 'XYZ-987',
            'notified_contacts_count' => 1,
        ]);

        Mail::assertSent(SosAlertMail::class, function (SosAlertMail $mail) {
            return $mail->hasTo('mama@example.com');
        });
        Mail::assertSent(SosAlertMail::class, 1);
    }

    public function test_sos_cannot_be_triggered_on_a_ride_that_is_not_in_progress(): void
    {
        $client = User::factory()->create();
        $ride = Ride::factory()->create(['client_user_id' => $client->id, 'status' => 'completed']);

        $this->actingAs($client)
            ->post(route('sos.store', $ride))
            ->assertSessionHasErrors('ride');
    }

    public function test_a_stranger_cannot_trigger_sos_on_someone_elses_ride(): void
    {
        $ride = Ride::factory()->create(['status' => 'in_progress']);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->post(route('sos.store', $ride))->assertForbidden();
    }

    public function test_only_an_admin_can_see_the_sos_alerts_audit_log(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)->get(route('admin.sos-alerts.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.sos-alerts.index'))->assertOk();
    }
}
