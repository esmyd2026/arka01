<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\TrustHosts;
use Tests\TestCase;

class TrustedHostsTest extends TestCase
{
    public function test_trusted_hosts_include_the_configured_application_host(): void
    {
        $patterns = $this->app->make(TrustHosts::class)->hosts();
        $configuredHost = parse_url(config('app.url'), PHP_URL_HOST);

        $this->assertTrue(collect($patterns)->contains(
            fn (string $pattern) => preg_match('/'.$pattern.'/i', $configuredHost) === 1
        ));
    }

    public function test_local_and_testing_environments_allow_localhost_only_as_an_extra_host(): void
    {
        $patterns = $this->app->make(TrustHosts::class)->hosts();

        $this->assertContains('^localhost$', $patterns);
        $this->assertContains('^127\.0\.0\.1$', $patterns);
        $this->assertFalse(collect($patterns)->contains(
            fn (string $pattern) => preg_match('/'.$pattern.'/i', 'evil.example') === 1
        ));
    }
}
