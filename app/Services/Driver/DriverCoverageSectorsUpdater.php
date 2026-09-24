<?php

namespace App\Services\Driver;

use App\Models\DriverProfile;
use App\Models\Sector;

/**
 * Guarda qué sectores cubre un conductor (pedido explícito del usuario:
 * "que los conductores puedan indicar la zona de trabajo"). Extraído a
 * propósito del enorme DriverProfileUpdater::update() — es un ajuste chico
 * e independiente (un solo campo, sin archivos ni verificación de
 * documentos de por medio), y este service se reusa igual de web y móvil.
 */
class DriverCoverageSectorsUpdater
{
    /**
     * @param  array<int, int>  $sectorIds
     * @return array<int, int> Los ids realmente guardados (los inválidos/inactivos quedan afuera en silencio).
     */
    public function update(DriverProfile $driverProfile, array $sectorIds): array
    {
        $validIds = Sector::query()
            ->whereIn('id', $sectorIds)
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        $driverProfile->coverageSectors()->sync($validIds);

        return $validIds;
    }
}
