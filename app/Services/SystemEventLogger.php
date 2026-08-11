<?php

namespace App\Services;

use App\Models\SystemEvent;

/**
 * Punto único para registrar un evento/error crítico en el módulo de
 * Monitoreo (sección 9 del roadmap de mejoras) — pensado para llamarse
 * justo donde el código YA sabía que algo había fallado (mismo lugar que
 * hoy solo escribía Log::warning()/error()), no para instrumentar todo el
 * sistema desde cero.
 *
 * Nunca pasar tokens, contraseñas u otro secreto en $message o $context —
 * esto se muestra tal cual en /admin/monitoreo (pedido explícito del
 * usuario: "no almacenar tokens, contraseñas u otros secretos dentro de
 * los mensajes de error").
 */
class SystemEventLogger
{
    public static function log(
        string $eventType,
        string $module,
        string $message,
        string $severity = 'error',
        array $context = [],
        ?int $userId = null,
        ?string $channel = null,
        ?string $providerErrorCode = null,
    ): SystemEvent {
        return SystemEvent::query()->create([
            'severity' => $severity,
            'module' => $module,
            'event_type' => $eventType,
            'channel' => $channel,
            'user_id' => $userId,
            'message' => $message,
            'provider_error_code' => $providerErrorCode,
            'last_attempt_at' => now(),
            'context' => $context,
        ]);
    }
}
