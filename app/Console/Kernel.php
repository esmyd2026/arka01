<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Motor de recurrencia de Expresos (sección 4.2 y 9.5): genera la
        // solicitud de carrera del día para cada Expreso activo, de madrugada.
        $schedule->command('express:generate-rides')->dailyAt('05:00');

        // Bug reportado por el usuario: conductores que quedaban "disponibles"
        // en la base sin estarlo de verdad (app cerrada, celular sin batería) —
        // ver el comando para el detalle completo.
        $schedule->command('drivers:sweep-stale-availability')->everyTwoMinutes();

        // Pedido explícito del usuario: recordatorio al conductor 15-20 min
        // antes de una carrera programada — ver el comando para el detalle.
        $schedule->command('rides:send-upcoming-reminders')->everyFiveMinutes();

        // Bug reportado por el usuario: una carrera/solicitud programada
        // cuya hora ya pasó se quedaba pendiente para siempre, sin ningún
        // aviso — ver los dos comandos para el detalle. Misma cadencia de
        // 5 min que el recordatorio de arriba, por consistencia.
        $schedule->command('rides:send-overdue-scheduled-alerts')->everyFiveMinutes();
        $schedule->command('rides:expire-overdue-scheduled-requests')->everyFiveMinutes();

        // Bug encontrado en una auditoría del flujo completo: una solicitud
        // INMEDIATA dirigida a un conductor puntual nunca vencía si no
        // respondía — ver el comando para el porqué de no usar el mismo
        // Job con delay() que ya usa la bolsa. Cada 2 min, mismo criterio
        // de cadencia que el barrido de disponibilidad de arriba.
        $schedule->command('rides:expire-overdue-directed-requests')->everyTwoMinutes();

        // Pedido explícito del usuario: avisar al conductor antes de que se
        // le cierre la ventana de WhatsApp de 24h, con un botón para
        // reabrirla — ver el comando para el detalle. El umbral de "por
        // vencer" es de 2h (WhatsAppSession::EXPIRING_SOON_THRESHOLD_HOURS),
        // así que cada 15 min alcanza para no dejar pasar el aviso.
        $schedule->command('whatsapp:notify-expiring-sessions')->everyFifteenMinutes();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
