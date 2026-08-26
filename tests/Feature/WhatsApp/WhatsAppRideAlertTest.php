<?php

namespace Tests\Feature\WhatsApp;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\RideRequest;
use App\Models\User;
use App\Models\WhatsAppSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Aviso de carrera nueva por WhatsApp (pedido explícito del usuario): solo se
 * manda si el conductor tiene la ventana de 24h abierta — mismo criterio de
 * "activado a mano en el test" que PhoneVerificationTest, sin credenciales
 * reales en .env.
 */
class WhatsAppRideAlertTest extends TestCase
{
    use RefreshDatabase;

    private function enableWhatsApp(): void
    {
        Config::set('services.whatsapp.token', 'fake-token');
        Config::set('services.whatsapp.phone_number_id', '123456');
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.fake']]], 200)]);
    }

    private function clientWithFleetDriver(?string $driverPhone = '+593991234567'): array
    {
        $client = User::factory()->create();
        $driver = User::factory()->create(['phone' => $driverPhone]);

        $fleet = Fleet::factory()->for($client, 'owner')->create();
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);
        DriverProfile::factory()->for($driver)->create(['is_available' => true]);

        return [$client, $driver, $fleet];
    }

    public function test_requesting_a_ride_sends_a_whatsapp_alert_to_a_driver_with_an_active_session(): void
    {
        $this->enableWhatsApp();
        [$client, $driver] = $this->clientWithFleetDriver();

        WhatsAppSession::query()->create(['user_id' => $driver->id, 'opened_at' => now(), 'expires_at' => now()->addHours(20)]);

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => $driver->id,
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
        ])->assertRedirect();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com')
            && $request['type'] === 'text'
            && $request['to'] === '593991234567');
    }

    public function test_requesting_a_ride_does_not_send_whatsapp_without_an_active_session(): void
    {
        $this->enableWhatsApp();
        [$client, $driver] = $this->clientWithFleetDriver();

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => $driver->id,
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
        ])->assertRedirect();

        Http::assertNothingSent();
    }

    /**
     * Pedido explícito del usuario: el mensaje de WhatsApp indica cuánto
     * tiempo tiene para responder — solo aplica al despacho secuencial
     * ("a toda la flota"), una solicitud dirigida no tiene vencimiento.
     */
    public function test_a_whole_fleet_request_includes_the_time_left_to_respond(): void
    {
        $this->enableWhatsApp();
        [$client, $driver] = $this->clientWithFleetDriver();

        WhatsAppSession::query()->create(['user_id' => $driver->id, 'opened_at' => now(), 'expires_at' => now()->addHours(20)]);

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => null,
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
        ])->assertRedirect();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com')
            && str_contains($request['text']['body'], 'segundos para aceptar'));
    }

    public function test_a_directed_request_does_not_mention_a_time_limit(): void
    {
        $this->enableWhatsApp();
        [$client, $driver] = $this->clientWithFleetDriver();

        WhatsAppSession::query()->create(['user_id' => $driver->id, 'opened_at' => now(), 'expires_at' => now()->addHours(20)]);

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => $driver->id,
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
        ])->assertRedirect();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com')
            && ! str_contains($request['text']['body'], 'segundos para aceptar'));
    }

    /**
     * Pedido explícito del usuario: si un candidato del despacho secuencial
     * rechaza (o se le acaba el tiempo), y la solicitud pasa a otro, el que
     * PERDIÓ el turno se entera por WhatsApp — no solo por la app.
     */
    public function test_the_previous_candidate_is_notified_by_whatsapp_when_the_offer_moves_on(): void
    {
        $this->enableWhatsApp();

        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();

        $driverA = User::factory()->create(['phone' => '+593991111111']);
        DriverProfile::factory()->for($driverA)->create(['is_available' => true, 'current_lat' => -0.1807, 'current_lng' => -78.4678]);
        FleetMember::factory()->for($fleet)->for($driverA, 'driver')->create(['added_by' => $client->id]);
        WhatsAppSession::query()->create(['user_id' => $driverA->id, 'opened_at' => now(), 'expires_at' => now()->addHours(20)]);

        $driverB = User::factory()->create(['phone' => '+593992222222']);
        DriverProfile::factory()->for($driverB)->create(['is_available' => true, 'current_lat' => -0.1820, 'current_lng' => -78.4690]);
        FleetMember::factory()->for($fleet)->for($driverB, 'driver')->create(['added_by' => $client->id]);
        WhatsAppSession::query()->create(['user_id' => $driverB->id, 'opened_at' => now(), 'expires_at' => now()->addHours(20)]);

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => null,
            'dispatch_pool' => 'fleet',
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
        ])->assertRedirect();

        $rideRequest = RideRequest::query()->where('client_user_id', $client->id)->firstOrFail();
        // A quien le haya tocado el turno primero es a quien le llega el
        // aviso de "se te acabó el tiempo" al rechazar — no importa cuál de
        // los dos haya sido, lo que se prueba es que le llega a ESE número.
        $assignedDriver = $rideRequest->driver_user_id === $driverA->id ? $driverA : $driverB;
        $assignedPhone = ltrim($assignedDriver->phone, '+');

        $this->actingAs($assignedDriver)->post(route('ride-requests.reject', $rideRequest))->assertRedirect();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com')
            && $request['to'] === $assignedPhone
            && str_contains($request['text']['body'], 'Se le acabó el tiempo'));
    }

    /**
     * Pedido explícito del usuario: el mensaje le recuerda al conductor que
     * si no quiere más solicitudes, tiene que desconectarse a propósito
     * desde la app — dejar la app en segundo plano ya no alcanza para
     * dejar de recibir avisos (ver DriverProfile::isReachable()).
     */
    public function test_the_new_ride_alert_reminds_the_driver_how_to_stop_notifications(): void
    {
        $this->enableWhatsApp();
        [$client, $driver] = $this->clientWithFleetDriver();

        WhatsAppSession::query()->create(['user_id' => $driver->id, 'opened_at' => now(), 'expires_at' => now()->addHours(20)]);

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => $driver->id,
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
        ])->assertRedirect();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com')
            && str_contains($request['text']['body'], 'Desconéctese desde la app'));
    }

    /**
     * Pedido explícito del usuario: "en los whatsapp manda la fecha y hora
     * de la solicitud de la carrera" — antes solo aparecía si era
     * programada; ahora siempre, y en hora de Ecuador sin importar la zona
     * horaria configurada en el servidor (ver config/app.php).
     */
    public function test_the_new_ride_alert_includes_the_requested_date_and_time_in_ecuador_time(): void
    {
        $this->enableWhatsApp();
        [$client, $driver] = $this->clientWithFleetDriver();

        WhatsAppSession::query()->create(['user_id' => $driver->id, 'opened_at' => now(), 'expires_at' => now()->addHours(20)]);

        $this->travelTo(now('America/Guayaquil')->setTime(21, 45));

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => $driver->id,
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
        ])->assertRedirect();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com')
            && str_contains($request['text']['body'], 'Solicitada: '.now('America/Guayaquil')->format('d/m/Y').' 21:45'));
    }

    public function test_an_expired_session_does_not_trigger_a_whatsapp_alert(): void
    {
        $this->enableWhatsApp();
        [$client, $driver] = $this->clientWithFleetDriver();

        WhatsAppSession::query()->create(['user_id' => $driver->id, 'opened_at' => now()->subDays(2), 'expires_at' => now()->subHour()]);

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => $driver->id,
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
        ])->assertRedirect();

        Http::assertNothingSent();
    }
}
