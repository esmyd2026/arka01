<?php

namespace App\Services;

/**
 * Pedido explícito del usuario: "una lista de sonidos que puedo seleccionar
 * para las notificaciones y que las pueda activar desde el panel
 * administrativo. y que tenga todo el volumen". Los sonidos en sí se
 * sintetizan en el navegador con Web Audio API (ver
 * resources/js/Utils/liveAlert.js) — acá solo se cura, para el selector del
 * admin, qué CATEGORÍAS de aviso existen y qué SONIDOS hay para elegir en
 * cada una. Mismo criterio que App\Services\QuickLinkRegistry: única fuente
 * de verdad para Admin\SystemController (arma los selects), pero las claves
 * de CATEGORIES y de SOUNDS tienen que existir tal cual en el catálogo
 * SOUND_PRESETS/DEFAULT_CATEGORY_SOUND de liveAlert.js — un sonido nuevo acá
 * no suena distinto hasta que el mismo key también exista ahí.
 */
class NotificationSoundRegistry
{
    /**
     * Cada categoría es la familia de avisos que hoy ya comparten un mismo
     * sonido en toda la app (Ride/Index.vue, Ride/Show.vue, Dashboard.vue,
     * AuthenticatedLayout.vue, pantallas de soporte, etc.) — no una por cada
     * evento puntual, para no explotar la lista en decenas de selects que
     * en la práctica siempre se dejarían igual.
     */
    public const CATEGORIES = [
        'attention' => ['label' => 'Cancelaciones, cambios e invitaciones de flota', 'default' => 'attention'],
        'update' => ['label' => 'Avisos generales (soporte, carrera aceptada)', 'default' => 'soft'],
        'cabin' => ['label' => 'Progreso de la carrera (en camino, llegó, recogió, completó)', 'default' => 'cabin'],
        'incoming_ride' => ['label' => 'Carrera nueva para el conductor', 'default' => 'emergency_siren'],
    ];

    public const SOUNDS = [
        'attention' => 'Atención (dos tonos ascendentes)',
        'urgent' => 'Urgente (tres tonos + vibración larga)',
        'cabin' => 'Campanita de cabina',
        'soft' => 'Suave (un solo tono)',
        'classic_bell' => 'Timbre clásico',
        'double_knock' => 'Doble golpe',
        'marimba' => 'Marimba',
        'siren' => 'Sirena intensa',
        'ding_dong' => 'Timbre de puerta',
        'emergency_siren' => 'Sirena de emergencia (5 segundos)',
        'repeating_alarm' => 'Alarma insistente (6 segundos)',
        'dispatch_horn' => 'Alerta de despacho (4 segundos)',
    ];

    /**
     * @param  array<string, string>  $selected
     * @return array<int, array{key: string, label: string, sound: string}>
     */
    public static function withState(array $selected): array
    {
        return collect(self::CATEGORIES)
            ->map(fn (array $category, string $key) => [
                'key' => $key,
                'label' => $category['label'],
                'sound' => $selected[$key] ?? $category['default'],
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array{key: string, label: string}> */
    public static function soundOptions(): array
    {
        return collect(self::SOUNDS)
            ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
            ->values()
            ->all();
    }
}
