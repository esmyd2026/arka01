<?php

namespace App\Notifications;

use App\Models\RideRequest;
use App\Services\Haversine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class CooperativeRideAssignedPushNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly RideRequest $rideRequest) {}

    public function via(object $notifiable): array
    {
        // database mantiene la alerta dentro de Arka01; WebPush la hace
        // visible y audible incluso con la aplicación en segundo plano.
        return ['database', WebPushChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        return $this->payload();
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $payload = $this->payload();

        return (new WebPushMessage)
            ->title('Unidad asignada por la cooperativa')
            ->body($payload['message'])
            ->icon('/icons/icon.svg')
            ->data(['url' => '/carreras'])
            ->action('Ver solicitud', 'view');
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $request = $this->rideRequest->loadMissing('driver.driverProfile');
        $driver = $request->driver;
        $profile = $driver?->driverProfile;
        $rating = $driver ? round((float) $driver->reviewsReceived()->avg('rating'), 1) : null;
        $vehicle = trim(implode(' ', array_filter([$profile?->vehicle_make, $profile?->vehicle_model])));
        $etaMinutes = null;

        if ($profile?->current_lat !== null && $profile?->current_lng !== null) {
            $km = Haversine::distanceKm(
                (float) $profile->current_lat,
                (float) $profile->current_lng,
                (float) $request->origin_lat,
                (float) $request->origin_lng,
            );
            $etaMinutes = max(1, (int) ceil(($km / 25) * 60));
        }

        $details = array_filter([
            $rating ? "★ {$rating}" : null,
            $vehicle ?: null,
            $etaMinutes ? "aprox. {$etaMinutes} min" : null,
        ]);

        return [
            'type' => 'cooperative_ride_assigned',
            'ride_request_id' => $request->id,
            'driver_user_id' => $driver?->id,
            'driver_name' => $driver?->name,
            'rating' => $rating,
            'vehicle' => $vehicle ?: null,
            'eta_minutes' => $etaMinutes,
            'message' => ($driver?->name ?? 'Un conductor').' fue asignado'.($details ? ' · '.implode(' · ', $details) : '').'.',
        ];
    }
}
