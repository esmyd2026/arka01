<?php

namespace App\Services\Driver;

use App\Events\DriverLocationUpdated;
use App\Jobs\NotifyDriverDisconnectedByWhatsApp;
use App\Models\DriverProfile;
use App\Services\DriverActivityTracker;

/**
 * Conectar/desconectar y publicar la posición general de un conductor —
 * extraído de DriverLocationController::update() (roadmap app móvil,
 * Hito 5: nunca duplicar una regla de negocio entre web y móvil).
 * Extracción literal, misma lógica que ya cubren VehicleCapacityTest,
 * WhatsAppDisconnectAlertTest y StaleDriverAvailabilityTest.
 */
class DriverAvailabilityUpdater
{
    public function __construct(private readonly DriverActivityTracker $activityTracker) {}

    public function update(DriverProfile $driverProfile, float $lat, float $lng, bool $isAvailable): void
    {
        // Solo se bloquea el encendido; desconectarse siempre debe seguir
        // siendo posible. abort(403, ...) tal cual el original — el
        // frontend web (DriverAvailabilityToggle.vue) distingue este
        // bloqueo permanente por el status 403 exacto, no por un 422 de
        // validación; el mismo contrato aplica para el móvil.
        if ($isAvailable && ($reason = $driverProfile->availabilityBlockReason())) {
            abort(403, $reason);
        }

        $wasAvailable = $driverProfile->is_available;

        $driverProfile->update([
            'current_lat' => $lat,
            'current_lng' => $lng,
            'is_available' => $isAvailable,
            'location_updated_at' => now(),
        ]);

        $this->activityTracker->record($driverProfile->user_id, $isAvailable);

        if ($wasAvailable && ! $isAvailable) {
            NotifyDriverDisconnectedByWhatsApp::dispatch($driverProfile->user_id);
        }

        broadcast(new DriverLocationUpdated($driverProfile))->toOthers();
    }
}
