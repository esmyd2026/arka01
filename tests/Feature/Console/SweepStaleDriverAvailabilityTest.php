<?php

namespace Tests\Feature\Console;

use App\Events\DriverLocationUpdated;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\WhatsAppSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Bug reportado por el usuario: un conductor "disponible" sin ping reciente
 * (app cerrada, celular sin batería) le quedaba pintado como conectado a los
 * clientes para siempre — ver App\Console\Commands\SweepStaleDriverAvailability.
 */
class SweepStaleDriverAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_driver_without_a_recent_ping_is_marked_unavailable(): void
    {
        Event::fake([DriverLocationUpdated::class]);

        $driver = User::factory()->create();
        $profile = DriverProfile::factory()->for($driver)->create([
            'is_available' => true,
            'location_updated_at' => now()->subMinutes(5),
        ]);

        $this->artisan('drivers:sweep-stale-availability')->assertExitCode(0);

        $this->assertFalse($profile->fresh()->is_available);
        Event::assertDispatched(DriverLocationUpdated::class, fn ($event) => $event->driverProfile->id === $profile->id);
    }

    public function test_a_driver_who_never_pinged_at_all_is_marked_unavailable(): void
    {
        $driver = User::factory()->create();
        $profile = DriverProfile::factory()->for($driver)->create([
            'is_available' => true,
            'location_updated_at' => null,
        ]);

        $this->artisan('drivers:sweep-stale-availability');

        $this->assertFalse($profile->fresh()->is_available);
    }

    public function test_a_driver_with_a_recent_ping_is_left_untouched(): void
    {
        $driver = User::factory()->create();
        $profile = DriverProfile::factory()->for($driver)->create([
            'is_available' => true,
            'location_updated_at' => now()->subSeconds(30),
        ]);

        $this->artisan('drivers:sweep-stale-availability');

        $this->assertTrue($profile->fresh()->is_available);
    }

    public function test_a_driver_already_unavailable_is_not_touched_or_broadcast(): void
    {
        Event::fake([DriverLocationUpdated::class]);

        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create([
            'is_available' => false,
            'location_updated_at' => now()->subDays(3),
        ]);

        $this->artisan('drivers:sweep-stale-availability');

        Event::assertNotDispatched(DriverLocationUpdated::class);
    }

    /**
     * Bug reportado por el usuario (caso real: el conductor Luis se
     * desconectaba solo por tener la app en segundo plano, aunque seguía
     * alcanzable por WhatsApp): con la ventana de 24h todavía abierta, el
     * barrido ya no lo marca desconectado.
     */
    public function test_a_stale_driver_with_an_active_whatsapp_session_is_left_available(): void
    {
        Event::fake([DriverLocationUpdated::class]);

        $driver = User::factory()->create();
        $profile = DriverProfile::factory()->for($driver)->create([
            'is_available' => true,
            'location_updated_at' => now()->subMinutes(5),
        ]);
        WhatsAppSession::query()->create(['user_id' => $driver->id, 'opened_at' => now(), 'expires_at' => now()->addHours(20)]);

        $this->artisan('drivers:sweep-stale-availability');

        $this->assertTrue($profile->fresh()->is_available);
        Event::assertNotDispatched(DriverLocationUpdated::class);
    }
}
