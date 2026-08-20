<?php

namespace App\Services;

use App\Models\DriverActivitySession;
use Carbon\CarbonInterface;

class DriverActivityTracker
{
    /** Registra el pulso GPS sin crear una fila nueva cada 15 segundos. */
    public function record(int $driverUserId, bool $isAvailable): void
    {
        if (! $isAvailable) {
            $this->close($driverUserId);

            return;
        }

        $session = DriverActivitySession::query()->firstOrCreate(
            ['driver_user_id' => $driverUserId, 'ended_at' => null],
            ['started_at' => now(), 'source' => 'gps'],
        );
        $session->forceFill(['last_seen_at' => now()])->save();
    }

    public function close(int $driverUserId, ?CarbonInterface $endedAt = null): void
    {
        $session = DriverActivitySession::query()
            ->where('driver_user_id', $driverUserId)
            ->whereNull('ended_at')
            ->latest('started_at')
            ->first();

        if (! $session) {
            return;
        }

        $end = $endedAt ?? $session->last_seen_at ?? now();
        $session->forceFill(['ended_at' => $end->greaterThan($session->started_at) ? $end : $session->started_at])->save();
    }
}
