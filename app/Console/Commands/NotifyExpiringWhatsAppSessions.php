<?php

namespace App\Console\Commands;

use App\Jobs\NotifyWhatsAppSessionExpiringSoon;
use App\Models\WhatsAppSession;
use Illuminate\Console\Command;

/**
 * Pedido explícito del usuario ("un proceso que job que cuando el conductor
 * se le esté acabando la ventana de las 24 horas le mande un mensaje que se
 * le va a cerrar la sesión, con un botón para restablecerla"): corre
 * periódicamente (Kernel::schedule) buscando la sesión VIGENTE de cada
 * conductor (la más reciente por `expires_at`, ver
 * App\Models\User::currentWhatsAppSession()) que esté dentro del umbral de
 * "por vencer" (App\Models\WhatsAppSession::EXPIRING_SOON_THRESHOLD_HOURS) y
 * todavía no haya recibido este aviso — `expiring_soon_notified_at` evita
 * mandarlo de nuevo en cada corrida mientras la sesión siga en ese rango.
 *
 * Solo conductores (pedido explícito del usuario) — un cliente con la
 * ventana por vencer simplemente deja de recibir avisos de carrera hasta que
 * vuelva a escribir, sin que eso le corte ninguna posibilidad de operar
 * (a diferencia del conductor, que depende de esa ventana para enterarse de
 * solicitudes nuevas si no tiene la app abierta).
 */
class NotifyExpiringWhatsAppSessions extends Command
{
    protected $signature = 'whatsapp:notify-expiring-sessions';

    protected $description = 'Avisa a los conductores cuya ventana de WhatsApp está por cerrarse, con un botón para reabrirla';

    public function handle(): int
    {
        $candidates = WhatsAppSession::query()
            ->whereNull('expiring_soon_notified_at')
            ->whereBetween('expires_at', [now(), now()->addHours(WhatsAppSession::EXPIRING_SOON_THRESHOLD_HOURS)])
            ->whereHas('user.driverProfile', fn ($query) => $query->whereNull('deactivated_at'))
            ->with('user')
            ->get();

        $notified = 0;

        foreach ($candidates as $session) {
            // Bug evitado a propósito: un usuario puede tener varias filas de
            // sesión (una por cada apertura de ventana) — solo importa avisar
            // si ESTA fila sigue siendo la vigente ahora mismo, no una vieja
            // que por casualidad cae en el rango pero ya fue reemplazada por
            // una apertura más nueva.
            if (! $session->user || $session->user->currentWhatsAppSession()?->id !== $session->id) {
                continue;
            }

            $session->update(['expiring_soon_notified_at' => now()]);

            // Encolado, no sincrónico (mismo criterio que
            // NotifyDriverDisconnectedByWhatsApp): esta corrida puede tocar
            // varios conductores, no debe esperar a cada llamada a Meta.
            NotifyWhatsAppSessionExpiringSoon::dispatch($session->user_id);
            $notified++;
        }

        if ($notified > 0) {
            $this->info("Avisados {$notified} conductor(es) con la sesión de WhatsApp por vencer.");
        }

        return self::SUCCESS;
    }
}
