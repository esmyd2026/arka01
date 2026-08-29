<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\AdminDriverLifecyclePushNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class AdminDriverAlertService
{
    public function registered(User $driver): void
    {
        $this->send($driver, 'registered');
    }

    public function readyForVerification(User $driver): void
    {
        $this->send($driver, 'ready');
    }

    private function send(User $driver, string $stage): void
    {
        SystemEventLogger::log(
            eventType: $stage === 'registered' ? 'driver_registered' : 'driver_ready_for_verification',
            module: 'driver_verification',
            message: $stage === 'registered'
                ? "{$driver->full_name} se registró como conductor."
                : "{$driver->full_name} completó el expediente para verificación.",
            severity: 'info',
            context: ['stage' => $stage, 'member_code' => $driver->member_code],
            userId: $driver->id,
        );

        try {
            $admins = User::query()->where('is_admin', true)->get();
            Notification::send($admins, new AdminDriverLifecyclePushNotification($driver, $stage));
        } catch (\Throwable $exception) {
            // Una falla del proveedor push nunca debe impedir el registro ni
            // el envío del expediente; el evento permanece en Monitoreo.
            Log::warning('No se pudo avisar a los administradores sobre el conductor.', [
                'driver_user_id' => $driver->id,
                'stage' => $stage,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
