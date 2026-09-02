<?php

namespace Tests\Feature;

use App\Models\LandingCtaEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingCtaAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_real_landing_interaction_is_recorded_only_once_per_session(): void
    {
        $session = [
            'landing_cta_token' => str_repeat('a', 48),
            'landing_cta_issued_at' => now()->subSeconds(5)->timestamp,
            'landing_cta_recorded_events' => [],
        ];
        $payload = [
            'event' => 'click',
            'target' => 'general',
            'visitor_token' => '5acbcd2e-f75d-4f3d-875f-385c91931b20',
            'interaction_token' => str_repeat('a', 48),
            'website' => '',
            'automated' => false,
            'path' => '/',
        ];

        $this->withSession($session)->withHeader('User-Agent', 'Mozilla/5.0 Chrome/140')
            ->postJson(route('landing-cta.store'), $payload)
            ->assertOk()
            ->assertJson(['recorded' => true]);

        $this->postJson(route('landing-cta.store'), $payload)->assertOk();

        $this->assertDatabaseCount('landing_cta_events', 1);
        $this->assertDatabaseHas('landing_cta_events', ['event_type' => 'click', 'target' => 'general']);
    }

    public function test_bot_signals_and_invalid_tokens_do_not_pollute_metrics(): void
    {
        $payload = [
            'event' => 'impression',
            'target' => 'general',
            'visitor_token' => 'a031c2f1-cf9c-47da-b3b6-f3cfb55ca9dc',
            'interaction_token' => str_repeat('b', 48),
            'website' => 'https://spam.example',
            'automated' => true,
            'path' => '/',
        ];

        $this->withSession([
            'landing_cta_token' => str_repeat('b', 48),
            'landing_cta_issued_at' => now()->subSeconds(5)->timestamp,
        ])->withHeader('User-Agent', 'HeadlessChrome bot')->postJson(route('landing-cta.store'), $payload)
            ->assertOk()
            ->assertJson(['recorded' => false]);

        $this->assertDatabaseCount('landing_cta_events', 0);
    }

    public function test_admin_metrics_include_the_landing_conversion_funnel(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        LandingCtaEvent::query()->create([
            'event_type' => 'impression',
            'target' => 'general',
            'visitor_hash' => str_repeat('1', 64),
            'session_hash' => str_repeat('2', 64),
            'landing_path' => '/',
        ]);
        LandingCtaEvent::query()->create([
            'event_type' => 'click',
            'target' => 'general',
            'visitor_hash' => str_repeat('1', 64),
            'session_hash' => str_repeat('2', 64),
            'landing_path' => '/',
        ]);

        $this->actingAs($admin)->get(route('admin.metrics.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Metrics')
                ->where('landingCta.unique_visitors_30d', 1)
                ->where('landingCta.unique_clicks_30d', 1)
                ->where('landingCta.conversion_rate', 100)
            );
    }
}
