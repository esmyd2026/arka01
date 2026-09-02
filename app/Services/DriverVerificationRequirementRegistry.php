<?php

namespace App\Services;

use App\Models\SiteSetting;

/**
 * Pedido explícito del usuario: "ayudame a quitar o no validaciones de los
 * conductores... para no limitar el registro de un conductor" y, tras
 * preguntarle cuáles, "permiteme desde el admin poder activar o no lo
 * obligatorio para que el conductor se le haga mas facil activarse" — en
 * vez de decidir a mano cuáles sacar, cada uno queda configurable. Mismo
 * patrón que App\Services\QuickLinkRegistry: una lista curada + un array de
 * keys apagadas en site_settings (vacío = todo exigido, el comportamiento
 * de siempre).
 *
 * Consumido por DriverProfileController::update() (qué exige al guardar/
 * pedir verificación) y DriverProfile::hasCompleteRegistrationInformation()
 * (qué exige para poder conectarse) — los dos chequean el mismo estado, así
 * que apagar un requisito acá lo saca de los dos lugares a la vez.
 */
class DriverVerificationRequirementRegistry
{
    public const ITEMS = [
        'identity_document' => 'Foto de cédula',
        'license_photo' => 'Foto de licencia',
        // Pedido explícito del usuario ("quitemos, ocultalo... coloquemos
        // mejor la matricula del vehiculo"): reemplaza a 'police_record' acá
        // y en Driver/Profile.vue — la columna police_record_path y los
        // documentos ya subidos con ella se conservan sin tocar (ver
        // DriverProfile::getPoliceRecordUrlAttribute()), solo dejó de
        // pedirse y de contar como requisito.
        'vehicle_registration' => 'Matrícula del vehículo',
        'has_insurance' => 'Declarar que cuenta con seguro',
        'profile_photo' => 'Foto de perfil',
    ];

    /**
     * @param  array<int, string>  $disabledKeys
     * @return array<int, array{key: string, label: string, enabled: bool}>
     */
    public static function withState(array $disabledKeys): array
    {
        return collect(self::ITEMS)
            ->map(fn (string $label, string $key) => [
                'key' => $key,
                'label' => $label,
                'enabled' => ! in_array($key, $disabledKeys, true),
            ])
            ->values()
            ->all();
    }

    public static function isRequired(string $key): bool
    {
        $disabled = SiteSetting::current()->disabled_driver_requirements ?? [];

        return ! in_array($key, $disabled, true);
    }
}
