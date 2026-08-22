<?php

namespace App\Services;

use App\Models\User;
use App\Models\WhatsAppSetting;

class WhatsAppRideAccess
{
    public static function notificationsEnabled(): bool
    {
        return WhatsAppSetting::current()->ride_notifications_enabled;
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
