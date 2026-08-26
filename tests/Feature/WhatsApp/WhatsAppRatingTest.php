<?php

namespace Tests\Feature\WhatsApp;

use App\Models\ChatbotConversation;
use App\Models\Fleet;
use App\Models\RatingReason;
use App\Models\Ride;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Services\Chatbot\ChatbotEngine;
use App\Services\WhatsAppFreeformSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pedido explícito del usuario: "permite que le avise al cliente cuando
 * cambie de estado su carrera y que califique por allí también y le
 * invite a seguir las redes."
 */
class WhatsAppRatingTest extends TestCase
{
    use RefreshDatabase;

    private function enableWhatsApp(): void
    {
        Config::set('services.whatsapp.token', 'fake-token');
        Config::set('services.whatsapp.phone_number_id', '123456');
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.fake']]], 200)]);
    }

    private function completedRide(User $client, User $driver, Fleet $fleet): Ride
    {
        return Ride::factory()->create([
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'completed',
            'settled_price' => 5.5,
        ]);
    }

    public function test_a_five_star_rating_saves_directly_and_invites_to_follow_social_media(): void
    {
        $this->enableWhatsApp();
        SiteSetting::current()->update(['instagram_url' => 'https://instagram.com/arka01']);
        $client = User::factory()->create(['phone' => '+593991234567']);
        $driver = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $ride = $this->completedRide($client, $driver, $fleet);

        app(ChatbotEngine::class)->respondTo($client->phone, $client, "wa_rate:{$ride->id}:5");

        $this->assertDatabaseHas('reviews', [
            'ride_id' => $ride->id,
            'reviewer_user_id' => $client->id,
            'reviewee_user_id' => $driver->id,
            'rating' => 5,
        ]);
        Http::assertSent(fn ($request) => str_contains($request['text']['body'] ?? '', '5 estrellas')
            && str_contains($request['text']['body'] ?? '', 'instagram.com/arka01'));
    }

    public function test_a_low_rating_asks_for_a_reason_before_saving(): void
    {
        $this->enableWhatsApp();
        $client = User::factory()->create(['phone' => '+593991234567']);
        $driver = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $ride = $this->completedRide($client, $driver, $fleet);

        app(ChatbotEngine::class)->respondTo($client->phone, $client, "wa_rate:{$ride->id}:2");

        $this->assertDatabaseMissing('reviews', ['ride_id' => $ride->id]);
        $this->assertSame('WA_RATING_REASON', ChatbotConversation::forPhone($client->phone)->pending_intent);

        $reason = RatingReason::query()->where('direction', 'client_to_driver')->where('is_active', true)->first();
        app(ChatbotEngine::class)->respondTo($client->phone, $client, "wa_reason:{$reason->id}");

        $this->assertDatabaseHas('reviews', [
            'ride_id' => $ride->id,
            'rating' => 2,
            'rating_reason_id' => $reason->id,
        ]);
        $this->assertNull(ChatbotConversation::forPhone($client->phone)->pending_intent);
        // Sin invitación a redes con una calificación baja.
        Http::assertSent(fn ($request) => str_contains($request['text']['body'] ?? '', 'vamos a revisar'));
        Http::assertNotSent(fn ($request) => str_contains($request['text']['body'] ?? '', 'Síganos'));
    }

    public function test_cannot_rate_someone_elses_ride(): void
    {
        $this->enableWhatsApp();
        $client = User::factory()->create(['phone' => '+593991234567']);
        $stranger = User::factory()->create();
        $driver = User::factory()->create();
        $fleet = Fleet::factory()->for($stranger, 'owner')->create();
        $ride = $this->completedRide($stranger, $driver, $fleet);

        app(ChatbotEngine::class)->respondTo($client->phone, $client, "wa_rate:{$ride->id}:5");

        $this->assertDatabaseMissing('reviews', ['ride_id' => $ride->id]);
    }

    public function test_completing_a_ride_sends_a_real_whatsapp_list_to_rate(): void
    {
        $this->enableWhatsApp();
        $client = User::factory()->create(['phone' => '+593991234567']);
        WhatsAppSession::query()->create(['user_id' => $client->id, 'opened_at' => now(), 'expires_at' => now()->addHours(20)]);
        $driver = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $ride = $this->completedRide($client, $driver, $fleet);

        WhatsAppFreeformSender::sendRideCompletedToClient($ride);

        Http::assertSent(function ($request) use ($ride) {
            if (($request['interactive']['type'] ?? null) !== 'list') {
                return false;
            }
            $rowIds = collect($request['interactive']['action']['sections'][0]['rows'] ?? [])->pluck('id');

            return $rowIds->contains("wa_rate:{$ride->id}:5") && $rowIds->contains("wa_rate:{$ride->id}:1");
        });
    }
}
