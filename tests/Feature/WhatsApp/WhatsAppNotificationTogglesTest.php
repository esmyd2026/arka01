<?php

namespace Tests\Feature\WhatsApp;

use App\Models\ChatbotMessage;
use App\Models\Fleet;
use App\Models\Ride;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Models\WhatsAppSetting;
use App\Services\WhatsAppFreeformSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pedido explícito del usuario: "ayudame a configurar los modulos que yo
 * active de envios de whatsapp... y si las desactivo entonce esas
 * notificaciones no llegaran" — un apagado por tipo puntual de aviso,
 * distinto (y encima) del apagado general que ya existía
 * (ride_notifications_enabled).
 */
class WhatsAppNotificationTogglesTest extends TestCase
{
    use RefreshDatabase;

    private function enableWhatsApp(): void
    {
        Config::set('services.whatsapp.token', 'fake-token');
        Config::set('services.whatsapp.phone_number_id', '123456');
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.fake']]], 200)]);
    }

    private function rideWithActiveClientSession(): Ride
    {
        $client = User::factory()->create(['phone' => '+593991234567']);
        $driver = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        WhatsAppSession::query()->create(['user_id' => $client->id, 'opened_at' => now(), 'expires_at' => now()->addHours(20)]);

        return Ride::factory()->create([
            'fleet_id' => $fleet->id, 'client_user_id' => $client->id, 'driver_user_id' => $driver->id,
        ]);
    }

    public function test_disabling_a_specific_type_stops_only_that_notification(): void
    {
        $this->enableWhatsApp();
        WhatsAppSetting::current()->update(['notify_ride_started' => false]);
        $ride = $this->rideWithActiveClientSession();

        WhatsAppFreeformSender::sendRideStartedToClient($ride);
        Http::assertNothingSent();

        WhatsAppFreeformSender::sendRideArrivedToClient($ride);
        Http::assertSentCount(1);
    }

    public function test_each_ride_status_message_is_tagged_with_its_type_for_the_cost_dashboard(): void
    {
        $this->enableWhatsApp();
        $ride = $this->rideWithActiveClientSession();

        WhatsAppFreeformSender::sendRideArrivedToClient($ride);

        $this->assertDatabaseHas('chatbot_messages', ['direction' => 'out']);
        $message = ChatbotMessage::query()->latest('id')->firstOrFail();
        $this->assertSame('ride_arrived', $message->meta['type']);
    }

    public function test_a_disabled_driver_alert_is_not_sent(): void
    {
        $this->enableWhatsApp();
        WhatsAppSetting::current()->update(['notify_driver_disconnected' => false]);
        $driver = User::factory()->create(['phone' => '+593991234567']);
        WhatsAppSession::query()->create(['user_id' => $driver->id, 'opened_at' => now(), 'expires_at' => now()->addHours(20)]);

        WhatsAppFreeformSender::sendDisconnectedAlert($driver);

        Http::assertNothingSent();
    }

    public function test_re_enabling_a_type_lets_the_notification_through_again(): void
    {
        $this->enableWhatsApp();
        $settings = WhatsAppSetting::current();
        $settings->update(['notify_ride_completed' => false]);
        $ride = $this->rideWithActiveClientSession();
        $ride->update(['status' => 'completed', 'settled_price' => 5]);

        WhatsAppFreeformSender::sendRideCompletedToClient($ride);
        Http::assertNothingSent();

        $settings->update(['notify_ride_completed' => true]);
        WhatsAppFreeformSender::sendRideCompletedToClient($ride);
        Http::assertSentCount(1);
    }
}
