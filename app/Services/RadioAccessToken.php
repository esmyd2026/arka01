<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;

class RadioAccessToken
{
    public function roomForRideRequest(int $rideRequestId): string
    {
        $secret = $this->sharedSecret();

        // El ID incremental nunca sale del servidor. La sala es estable para
        // la solicitud y su carrera, pero no se puede enumerar sin el secreto.
        return 'arka01-'.hash_hmac('sha256', 'ride-request:'.$rideRequestId, $secret);
    }

    public function roomForChannel(string $channelPublicId): string
    {
        return 'arka01-'.hash_hmac('sha256', 'radio-channel:'.$channelPublicId, $this->sharedSecret());
    }

    /**
     * Crea una credencial HMAC corta, entendible por el microservicio Node.
     * La sala la deriva RideRadioAccess exclusivamente desde la solicitud
     * autorizada; el navegador no puede elegir ni sustituir ese valor.
     *
     * @return array{token: string, expires_at: CarbonImmutable}
     */
    public function issue(User $user, string $roomId): array
    {
        $secret = $this->sharedSecret();

        $now = CarbonImmutable::now();
        $ttl = max(60, min((int) config('radio.token_ttl_seconds', 1800), 86400));
        $expiresAt = $now->addSeconds($ttl);
        $claims = [
            // Nunca exponemos la llave incremental al repetidor.
            'sub' => $user->public_id,
            'name' => $user->full_name ?: $user->name,
            'role' => $user->isDriver() ? 'conductor' : 'cliente',
            'room' => $roomId,
            'iat' => $now->timestamp,
            'exp' => $expiresAt->timestamp,
            'nonce' => bin2hex(random_bytes(12)),
        ];

        $payload = $this->base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR));
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $payload, $secret, true));

        return [
            'token' => $payload.'.'.$signature,
            'expires_at' => $expiresAt,
        ];
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function sharedSecret(): string
    {
        $secret = (string) config('radio.shared_secret');

        if (strlen($secret) < 64) {
            abort(503, 'La radio todavía no está configurada.');
        }

        return $secret;
    }
}
