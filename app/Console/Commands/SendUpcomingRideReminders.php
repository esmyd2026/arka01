<?php

namespace App\Console\Commands;

use App\Events\RideReminderDue;
use App\Models\Ride;
use App\Notifications\RideScheduledReminderPushNotification;
use App\Services\WhatsAppFreeformSender;
use Illuminate\Console\Command;

/**
 * Pedido explícito del usuario: avisarle al conductor 15-20 min antes de una
 * carrera programada, para que no se le pase la hora de salir. Corre cada 5
 * minutos (Kernel::schedule) — la ventana de 5 minutos de ancho encaja justo
 * con esa frecuencia, así ninguna carrera se cuela sin pasar por acá ni se
 * avisa dos veces (`driver_reminder_sent_at` como bandera, mismo criterio
 * que las demás columnas `*_at` de `rides`).
 */
class SendUpcomingRideReminders extends Command
{
    protected $signature = 'rides:send-upcoming-reminders';

    protected $description = 'Avisa al conductor 15-20 minutos antes de una carrera programada';

    public function handle(): int
    {
        $upcoming = Ride::query()
            ->where('status', 'scheduled')
            ->whereNull('driver_reminder_sent_at')
            ->whereHas('rideRequest', function ($query) {
                $query->whereBetween('scheduled_at', [now()->addMinutes(15), now()->addMinutes(20)]);
            })
            ->with(['client', 'driver', 'rideRequest'])
            ->get();

        foreach ($upcoming as $ride) {
            $ride->update(['driver_reminder_sent_at' => now()]);

            broadcast(new RideReminderDue($ride));

            $ride->driver->notify(new RideScheduledReminderPushNotification($ride));
            WhatsAppFreeformSender::sendScheduledRideReminder($ride->driver, $ride);
        }

        if ($upcoming->isNotEmpty()) {
            $this->info("Recordatorio mandado para {$upcoming->count()} carrera(s) programada(s).");
        }

        return self::SUCCESS;
    }
}
