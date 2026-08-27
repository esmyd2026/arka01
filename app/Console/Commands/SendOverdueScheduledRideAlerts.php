<?php

namespace App\Console\Commands;

use App\Events\RideOverdueAlert;
use App\Models\Ride;
use App\Notifications\RideOverdueSchedulePushNotification;
use App\Services\WhatsAppFreeformSender;
use Illuminate\Console\Command;

/**
 * Bug reportado por el usuario: una carrera PROGRAMADA (Ride status=
 * 'scheduled') cuya hora ya pasó se quedaba mostrando "Iniciar viaje" al
 * conductor para siempre, sin que nada la marcara como vencida ni avisara.
 * Simétrico a SendUpcomingRideReminders (el aviso de 15-20 min ANTES) — acá
 * es 15+ min DESPUÉS. No cancela ni cambia el estado de la carrera: el
 * conductor puede seguir estando en camino con tráfico, esto solo avisa una
 * vez (`overdue_alert_sent_at` como bandera, mismo criterio que
 * `driver_reminder_sent_at`).
 */
class SendOverdueScheduledRideAlerts extends Command
{
    protected $signature = 'rides:send-overdue-scheduled-alerts';

    protected $description = 'Avisa al conductor cuando una carrera programada ya venció y no la inició';

    public function handle(): int
    {
        $overdue = Ride::query()
            ->where('status', 'scheduled')
            ->whereNull('overdue_alert_sent_at')
            ->whereHas('rideRequest', function ($query) {
                $query->where('scheduled_at', '<', now()->subMinutes(15));
            })
            ->with(['client', 'driver', 'rideRequest'])
            ->get();

        foreach ($overdue as $ride) {
            $ride->update(['overdue_alert_sent_at' => now()]);

            broadcast(new RideOverdueAlert($ride));

            $ride->driver->notify(new RideOverdueSchedulePushNotification($ride));
            WhatsAppFreeformSender::sendScheduledRideOverdueAlert($ride->driver, $ride);
        }

        if ($overdue->isNotEmpty()) {
            $this->info("Aviso de vencida mandado para {$overdue->count()} carrera(s) programada(s).");
        }

        return self::SUCCESS;
    }
}
