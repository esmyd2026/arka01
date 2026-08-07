<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Auditoría de seguridad (pedido explícito del usuario): sin validar la
 * firma HMAC de Meta, cualquiera que conociera la URL del webhook podía
 * mandar un POST armado a mano y que se procesara como un mensaje real —
 * ver App\Http\Middleware\VerifyWhatsAppSignature.
 */
class WhatsAppWebhookSignatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_request_with_an_invalid_signature_is_rejected(): void
    {
        Config::set('services.whatsapp.app_secret', 'shh-secreto-de-prueba');

        $body = json_encode(['entry' => []]);

        $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=firma-que-no-coincide',
        ], $body)->assertForbidden();
    }

    public function test_a_request_without_any_signature_header_is_rejected(): void
    {
        Config::set('services.whatsapp.app_secret', 'shh-secreto-de-prueba');

        $body = json_encode(['entry' => []]);

        $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertForbidden();
    }

    public function test_a_request_with_the_right_signature_is_accepted(): void
    {
        Config::set('services.whatsapp.app_secret', 'shh-secreto-de-prueba');

        $body = json_encode(['entry' => []]);
        $validSignature = 'sha256='.hash_hmac('sha256', $body, 'shh-secreto-de-prueba');

        $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $validSignature,
        ], $body)->assertOk();
    }

    /**
     * Mientras WHATSAPP_APP_SECRET no esté completado en .env (como hoy en
     * los demás tests de este webhook), se sigue procesando sin firma — no
     * rompe la integración mientras el usuario no complete esa variable.
     */
    public function test_without_a_configured_secret_the_signature_is_not_enforced(): void
    {
        Config::set('services.whatsapp.app_secret', null);

        $body = json_encode(['entry' => []]);

        $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();
    }
}
