<?php

namespace App\Services\Auth;

use App\Events\DriverLocationUpdated;
use App\Jobs\NotifyDriverDisconnectedByWhatsApp;
use App\Models\User;
use App\Services\DriverActivityTracker;

/**
 * Extraído de AuthenticatedSessionController::destroy() para reusarlo tal
 * cual desde el logout móvil (Api\V1\AuthController): un conductor que cierra
 * sesión —desde donde sea— no puede seguir apareciendo "disponible" para su
 * flota (bug reportado por el usuario), y avisa por WhatsApp igual que si
 * hubiera tocado "Desconectarme" a mano.
 */
class DriverOfflineOnLogout
{
    public function __construct(private readonly DriverActivityTracker $activityTracker) {}

    public function handle(?User $user): void
    {
        if (! $user?->isDriver() || ! $user->driverProfile->is_available) {
            return;
        }

        $user->driverProfile->update(['is_available' => false]);
        $this->activityTracker->close($user->id, now());
        broadcast(new DriverLocationUpdated($user->driverProfile));
        NotifyDriverDisconnectedByWhatsApp::dispatch($user->id);
    }
}
