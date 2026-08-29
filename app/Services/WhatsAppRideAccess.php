<?php

namespace App\Services;

use App\Models\User;
use App\Models\WhatsAppSetting;

class WhatsAppRideAccess
{
    /**
     * Cada tipo de aviso puntual (pedido explícito del usuario: "que yo las
     * active o desactive y si las desactivo entonce esas notificaciones no
     * llegaran") mapeado a su columna en `whatsapp_settings` — ver
     * App\Services\WhatsAppFreeformSender, que llama a
     * notificationTypeEnabled() con esta misma key antes de mandar cada una.
     * Única fuente de verdad, reusada también por
     * Admin\WhatsAppSettingController para armar la lista de toggles +
     * indicadores del panel.
     */
    public const NOTIFICATION_TYPES = [
        'ride_accepted' => ['label' => 'Carrera aceptada', 'group' => 'cliente'],
        'ride_started' => ['label' => 'Conductor en camino', 'group' => 'cliente'],
        'ride_arrived' => ['label' => 'Conductor llegó', 'group' => 'cliente'],
        'ride_picked_up' => ['label' => 'Viaje iniciado', 'group' => 'cliente'],
        'ride_completed' => ['label' => 'Carrera completada (y calificar)', 'group' => 'cliente'],
        'new_ride_alert' => ['label' => 'Carrera nueva', 'group' => 'conductor'],
        'cooperative_invitation' => ['label' => 'Invitación de cooperativa', 'group' => 'conductor'],
        'scheduled_reminder' => ['label' => 'Recordatorio de carrera programada', 'group' => 'conductor'],
        'scheduled_overdue' => ['label' => 'Carrera programada vencida', 'group' => 'conductor'],
        'offer_expired' => ['label' => 'Se acabó el tiempo para responder', 'group' => 'conductor'],
        'driver_disconnected' => ['label' => 'Aviso de desconexión', 'group' => 'conductor'],
        'session_expiring_soon' => ['label' => 'Sesión de WhatsApp por vencer', 'group' => 'conductor'],
    ];

    public static function notificationsEnabled(): bool
    {
        return WhatsAppSetting::current()->ride_notifications_enabled;
    }

    /**
     * true si este tipo puntual de aviso está prendido — se suma AL apagado
     * general de arriba, nunca lo reemplaza (los avisos de carrera siguen
     * exigiendo también notificationsEnabled()==true, ver
     * WhatsAppFreeformSender). Una key que no esté en NOTIFICATION_TYPES
     * (ej. algo de seguridad de cuenta) deja pasar siempre — no todo aviso
     * es apagable a propósito.
     */
    public static function notificationTypeEnabled(string $key): bool
    {
        if (! array_key_exists($key, self::NOTIFICATION_TYPES)) {
            return true;
        }

        return (bool) (WhatsAppSetting::current()->{"notify_{$key}"} ?? true);
    }

    public static function driverCanOperate(User $driver): bool
    {
        $settings = WhatsAppSetting::current();
        $profile = $driver->driverProfile;

        if (! $settings->driver_ride_actions_enabled || ! $profile?->whatsapp_ride_actions_enabled) {
            return false;
        }

        $membership = $profile->cooperativeMembership();

        return ! $membership || (bool) $membership->cooperative?->whatsapp_ride_actions_enabled;
    }

    public static function clientCanBook(): bool
    {
        return WhatsAppSetting::current()->client_ride_booking_enabled;
    }
}
