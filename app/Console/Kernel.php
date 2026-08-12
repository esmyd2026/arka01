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
