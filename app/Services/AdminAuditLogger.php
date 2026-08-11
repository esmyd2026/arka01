<?php

namespace App\Services;

use App\Models\AdminAuditLog;

/**
 * Punto único para dejar asentado un cambio administrativo crítico (sección
 * 18 del roadmap de mejoras). $sensitiveKeys marca qué claves de
 * $oldValue/$newValue son secretos: para esas, se guarda solo si cambiaron
 * o no ("cambiado"/sin cambios), nunca el valor real — el resto de las
 * claves se guarda tal cual.
 */
class AdminAuditLogger
{
    public static function log(
        int $adminUserId,
        string $action,
        string $module,
        array $oldValue = [],
        array $newValue = [],
        array $sensitiveKeys = [],
    ): AdminAuditLog {
        return AdminAuditLog::query()->create([
            'admin_user_id' => $adminUserId,
            'action' => $action,
            'module' => $module,
            'old_value' => self::redact($oldValue, $newValue, $sensitiveKeys, from: true),
            'new_value' => self::redact($oldValue, $newValue, $sensitiveKeys, from: false),
            'created_at' => now(),
        ]);
    }

    private static function redact(array $oldValue, array $newValue, array $sensitiveKeys, bool $from): array
    {
        $source = $from ? $oldValue : $newValue;

        foreach ($sensitiveKeys as $key) {
            if (! array_key_exists($key, $source)) {
                continue;
            }

            $source[$key] = ($oldValue[$key] ?? null) !== ($newValue[$key] ?? null) ? 'cambiado' : 'sin cambios';
        }

        return $source;
    }
}
