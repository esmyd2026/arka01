<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envía un push nativo (FCM, HTTP v1) a un solo dispositivo. Groundwork del
 * Hito 6 (roadmap app móvil) — reemplaza el "timbre" de WebPush (solo
 * navegador/PWA abiertos) por uno que llega con la app cerrada.
 *
 * Implementación directa (JWT firmado a mano con OpenSSL + Guzzle vía
 * Illuminate\Http) en vez del SDK oficial kreait/firebase-php: ese paquete
 * todavía no soporta PHP 8.5 (el que corre este proyecto) sin forzar
 * versiones incompatibles de lcobucci/jwt y la extensión sodium, que no
 * está instalada acá. El flujo en sí (JWT Bearer de cuenta de servicio →
 * access_token de OAuth2 → POST a fcm.googleapis.com) es el mismo que usa
 * ese SDK por dentro, documentado por Google — no es una integración
 * "casera" arriesgada.
 *
 * Sin `FIREBASE_PROJECT_ID`/`FIREBASE_CREDENTIALS_PATH` en .env (ver
 * PENDIENTES_USUARIO_APP_MOVIL.md, punto 1) este servicio simplemente no
 * manda nada — nunca bloquea el resto del flujo de notificaciones.
 */
class FcmSender
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public function isConfigured(): bool
    {
        $path = config('fcm.credentials_path');

        return config('fcm.project_id')
            && is_string($path)
            && $path !== ''
            && is_readable($path);
    }

    /**
     * @param  array<string, string>  $data  Solo strings — mismo requisito
     *                                       que impone la API de FCM para
     *                                       el payload "data".
     */
    public function send(string $deviceToken, string $title, string $body, array $data = []): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $accessToken = $this->accessToken();

        if (! $accessToken) {
            return false;
        }

        try {
            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->post('https://fcm.googleapis.com/v1/projects/'.config('fcm.project_id').'/messages:send', [
                    'message' => [
                        'token' => $deviceToken,
                        'notification' => [
                            'title' => $title,
                            'body' => $body,
                        ],
                        'data' => $data,
                        // Prioridad alta (pedido implícito del usuario: "el
                        // timbre de una carrera nueva" tiene que despertar el
                        // teléfono, no esperar el próximo barrido de batería
                        // de Android/iOS).
                        'android' => ['priority' => 'high'],
                        'apns' => ['headers' => ['apns-priority' => '10']],
                    ],
                ]);

            if ($response->failed()) {
                Log::warning('FCM rechazó el envío de un push.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('No se pudo enviar el push nativo.', ['exception' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Access token de OAuth2 para la cuenta de servicio — Google los emite
     * por 1 hora; se cachea 55 minutos para no firmar un JWT nuevo en cada
     * notificación.
     */
    private function accessToken(): ?string
    {
        return Cache::remember('fcm:service-account-access-token', now()->addMinutes(55), function () {
            $credentials = json_decode((string) file_get_contents(config('fcm.credentials_path')), true);

            if (! is_array($credentials) || empty($credentials['client_email']) || empty($credentials['private_key'])) {
                Log::warning('El archivo de credenciales de Firebase no tiene el formato esperado.');

                return null;
            }

            $jwt = $this->signServiceAccountJwt($credentials['client_email'], $credentials['private_key']);

            $response = Http::asForm()->timeout(10)->post(self::TOKEN_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if ($response->failed()) {
                Log::warning('No se pudo obtener el access_token de la cuenta de servicio de Firebase.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            return $response->json('access_token');
        });
    }

    /** JWT Bearer de cuenta de servicio (RFC 7523), firmado RS256 con OpenSSL — sin dependencias externas. */
    private function signServiceAccountJwt(string $clientEmail, string $privateKey): string
    {
        $now = time();

        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $this->base64UrlEncode(json_encode([
            'iss' => $clientEmail,
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $unsigned = "{$header}.{$claims}";
        openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        return "{$unsigned}.".$this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
