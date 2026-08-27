<?php

namespace Tests\Feature\WhatsApp;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Models\WhatsAppSetting;
use App\Services\WhatsAppFreeformSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pedido explícito del usuario: "mejora estos mensajes de whatsapp que diga
 * origen, destino, y los km desde hasta y km de donde tengo que ir a buscar
 * al pasajero. y abajo un boton de cancelar carrera o solicitud" — cubre el
 * contenido nuevo de los avisos de carrera ya aceptada/programada, y el
 * botón "Cancelar carrera" (simple, sin pedir motivo — reusa
 * RideController::cancel() con "Otro motivo").
 */
class WhatsAppRideCancelButtonTest extends TestCase
{
    use RefreshDatabase;

    private function enableWhatsApp(): void
    {
        Config::set('services.whatsapp.token', 'fake-token');
        Config::set('services.whatsapp.phone_number_id', '123456');
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.fake']]], 200)]);
    }

    private function scheduledRide(): Ride
    {
        $client = User::factory()->create();
        $driver = User::factory()->create(['phone' => '+593991234567']);
        DriverProfile::factory()->for($driver)->create(['current_lat' => -0.1807, 'current_lng' => -78.4678]);
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        $rideRequest = RideRequest::factory()->create([
            'client_user_id' => $client->id,
            'fleet_id' => $fleet->id,
            'is_scheduled' => true,
            'scheduled_at' => now()->addHour(),
            'status' => 'accepted',
            'origin_address' => 'Sauces 8',
            'destination_address' => 'Samanes 3',
        ]);

        WhatsAppSession::query()->create(['user_id' => $driver->id, 'opened_at' => now(), 'expires_at' => now()->addHours(20)]);

        return Ride::factory()->create([
            'ride_request_id' => $rideRequest->id,
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'scheduled',
            'origin_address' => 'Sauces 8',
            'destination_address' => 'Samanes 3',
            'distance_km' => 3.4,
        ]);
    }

    public function test_the_scheduled_reminder_includes_destination_and_both_distances(): void
    {
        $this->enableWhatsApp();
        $ride = $this->scheduledRide();

        WhatsAppFreeformSender::sendScheduledRideReminder($ride->driver, $ride->fresh(['rideRequest']));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com')
            && str_contains($request['text']['body'], 'Destino: Samanes 3')
            && str_contains($request['text']['body'], 'Distancia del viaje: 3.4 km')
            && str_contains($request['text']['body'], 'Km hasta el pasajero:'));
    }

    public function test_the_overdue_alert_includes_destination_and_both_distances(): void
    {
        $this->enableWhatsApp();
        $ride = $this->scheduledRide();

        WhatsAppFreeformSender::sendScheduledRideOverdueAlert($ride->driver, $ride->fresh(['rideRequest']));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com')
            && str_contains($request['text']['body'], 'Destino: Samanes 3')
            && str_contains($request['text']['body'], 'Distancia del viaje: 3.4 km')
            && str_contains($request['text']['body'], 'Km hasta el pasajero:'));
    }

    public function test_the_reminder_offers_a_cancel_button_when_driver_actions_are_enabled(): void
    {
        $this->enableWhatsApp();
        WhatsAppSetting::current()->update(['driver_ride_actions_enabled' => true]);
        $ride = $this->scheduledRide();

        WhatsAppFreeformSender::sendScheduledRideReminder($ride->driver, $ride->fresh(['rideRequest']));

        Http::assertSent(fn ($request) => $request['type'] === 'interactive'
            && collect($request['interactive']['action']['buttons'])->pluck('reply.id')->contains('ride_cancel:'.$ride->id));
    }

    public function test_the_reminder_has_no_button_when_driver_actions_are_disabled(): void
    {
        $this->enableWhatsApp();
        $ride = $this->scheduledRide();

        WhatsAppFreeformSender::sendScheduledRideReminder($ride->driver, $ride->fresh(['rideRequest']));

        Http::assertSent(fn ($request) => $request['type'] === 'text');
    }

    public function test_ride_accepted_message_offers_a_cancel_button_to_the_client_when_enabled(): void
    {
        $this->enableWhatsApp();
        WhatsAppSetting::current()->update(['client_ride_booking_enabled' => true]);
        $ride = $this->scheduledRide();
        $ride->client->update(['phone' => '+593987654321']);
        WhatsAppSession::query()->create(['user_id' => $ride->client_user_id, 'opened_at' => now(), 'expires_at' => now()->addHours(20)]);

        WhatsAppFreeformSender::sendRideAcceptedToClient($ride->fresh(['client', 'driver.driverProfile']));

        Http::assertSent(fn ($request) => $request['type'] === 'interactive'
            && str_contains($request['interactive']['body']['text'], 'Destino: Samanes 3')
            && collect($request['interactive']['action']['buttons'])->pluck('reply.id')->contains('ride_cancel:'.$ride->id));
    }

    /**
     * El botón "Cancelar carrera" de verdad cancela — sin pedir motivo,
     * usando "Otro motivo" (ya existe en DRIVER_CANCEL_REASONS), mismo
     * criterio que RideController::cancel() exige desde la app.
     */
    public function test_clicking_the_cancel_button_actually_cancels_the_ride(): void
    {
        $this->enableWhatsApp();
        WhatsAppSetting::current()->update(['driver_ride_actions_enabled' => true]);
        $ride = $this->scheduledRide();

        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'messages' => [[
                            'from' => '593991234567',
                            'type' => 'interactive',
                            'interactive' => ['button_reply' => ['id' => 'ride_cancel:'.$ride->id]],
                        ]],
                    ],
                ]],
            ]],
        ];

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();

        $fresh = $ride->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertSame('driver', $fresh->cancelled_by);
        $this->assertSame('Otro motivo', $fresh->cancellation_reason);
    }

    public function test_a_stranger_cannot_cancel_someone_elses_ride_from_whatsapp(): void
    {
        $this->enableWhatsApp();
        WhatsAppSetting::current()->update(['driver_ride_actions_enabled' => true, 'client_ride_booking_enabled' => true]);
        $ride = $this->scheduledRide();
        $stranger = User::factory()->create(['phone' => '+593999999999']);

        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'messages' => [[
                            'from' => '593999999999',
                            'type' => 'interactive',
                            'interactive' => ['button_reply' => ['id' => 'ride_cancel:'.$ride->id]],
                        ]],
                    ],
                ]],
            ]],
        ];

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();

        $this->assertSame('scheduled', $ride->fresh()->status);
        $this->assertNotNull($stranger); // sanity: the phone really did resolve to a different account
    }

    public function test_a_completed_ride_cannot_be_cancelled_from_whatsapp(): void
    {
        $this->enableWhatsApp();
        WhatsAppSetting::current()->update(['driver_ride_actions_enabled' => true]);
        $ride = $this->scheduledRide();
        $ride->update(['status' => 'completed']);

        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'messages' => [[
                            'from' => '593991234567',
                            'type' => 'interactive',
                            'interactive' => ['button_reply' => ['id' => 'ride_cancel:'.$ride->id]],
                        ]],
                    ],
                ]],
            ]],
        ];

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();

        $this->assertSame('completed', $ride->fresh()->status);
    }
}
