<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class ContentSecurityPolicyTest extends TestCase
{
    public function test_production_csp_allows_required_external_images(): void
    {
        // Validamos directamente el middleware para no acoplar esta prueba a
        // sesiones, rutas ni tablas. "testing" reproduce la rama no-local
        // que también se ejecuta en producción.
        $response = (new SecurityHeaders)->handle(
            Request::create('/dashboard'),
            fn () => new Response,
        );

        $policy = (string) $response->headers->get('Content-Security-Policy');

        $this->assertNotSame('', $policy);
        $this->assertStringContainsString(
            'https://*.basemaps.cartocdn.com',
            $policy,
            'La CSP debe permitir las teselas CARTO utilizadas por FleetMap.',
        );
        $this->assertStringContainsString(
            'https://lh3.googleusercontent.com',
            $policy,
            'La CSP debe permitir los avatares entregados por Google OAuth.',
        );
    }
}
