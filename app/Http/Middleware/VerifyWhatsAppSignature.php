<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Auditoría de seguridad (pedido explícito del usuario): sin esto, cualquiera
 * que conozca la URL del webhook de WhatsApp (App\Http\Controllers\WhatsAppWebhookController::receive)
 * puede mandar un POST armado a mano y el servidor lo procesa como si fuera
 * un mensaje real de Meta — abre ventanas de sesión, completa teléfonos de
 * cuentas ajenas, dispara notificaciones falsas. Meta firma cada POST con
 * HMAC-SHA256 del body crudo usando el App Secret; acá se recalcula esa
 * firma y se compara con el header X-Hub-Signature-256 que mandó Meta.
 */
class VerifyWhatsAppSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.whatsapp.app_secret');

        // Mientras WHATSAPP_APP_SECRET no esté completado en .env, se deja
        // pasar sin validar — mismo criterio que el resto de la integración
        // de WhatsApp (sin credenciales, la funcionalidad se degrada en vez
        // de romperse). Conviene completarlo antes de producción.
        if (blank($secret)) {
            return $next($request);
        }

        $signature = (string) $request->header('X-Hub-Signature-256', '');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            Log::warning('WhatsApp: firma de webhook inválida, request rechazado.', [
                'ip' => $request->ip(),
                'has_signature_header' => $signature !== '',
            ]);

            abort(403, 'Firma de webhook inválida.');
        }

        return $next($request);
    }
}
