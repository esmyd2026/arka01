<?php

namespace App\Console\Commands;

use App\Events\DriverLocationUpdated;
use App\Jobs\NotifyDriverDisconnectedByWhatsApp;
use App\Models\DriverProfile;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Bug reportado por el usuario: un conductor aparecía "disponible" para el
 * cliente sin estarlo de verdad — la app cerrada, el celular sin batería o
 * sin señal simplemente deja de mandar el ping de ubicación
 * (DriverAvailabilityToggle.vue, cada ~15s), pero nada apagaba
 * `is_available` en la base, así que quedaba prendido para siempre hasta que
 * alguien volviera a tocar el switch a mano.
 *
 * Corre cada 2 minutos (Kernel::schedule): a un conductor disponible que no
 * mandó su ubicación en los últimos 2 minutos (8 pings seguidos perdidos, ya
 * no es un simple hueco de red) se lo marca desconectado de verdad, y se
 * avisa por WebSocket con el mismo evento que ya usa un ping real — así
 * cualquier pantalla de "Mi flota"/"Pedir carrera" que esté abierta lo saca
 * de la lista de disponibles sin esperar a que alguien recargue.
 *
 * Excepción (bug reportado por el usuario, caso real: un conductor con la
 * app en segundo plano seguía "conectado" de hecho porque le llegaban los
 * avisos por WhatsApp, pero el barrido igual lo desconectaba y los clientes
 * lo veían offline): si todavía tiene la ventana de WhatsApp de 24h abierta,
 * no se lo desconecta acá — sigue alcanzable por ese canal aunque el GPS del
 * navegador esté viejo (ver DriverProfile::isReachable()).
 */
class SweepStaleDriverAvailability extends Command
{
    protected $signature = 'drivers:sweep-stale-availability';

    protected $description = 'Marca como desconectados a los conductores "disponibles" que dejaron de mandar su ubicación';

    public function handle(): int
    {
        // Mismo umbral que DriverProfile::isStale() (única fuente de
        // verdad, editable desde /admin/tarifas) — este comando es el
        // respaldo que apaga `is_available` de verdad en la base; isStale()
        // es lo que ya descarta a estos conductores de la lista de
        // candidatos ANTES de que corra esto.
        $staleAfterMinutes = DriverProfile::staleAfterMinutes();

        $stale = DriverProfile::query()
            ->where('is_available', true)
            ->where(function (Builder $query) use ($staleAfterMinutes) {
                $query->whereNull('location_updated_at')
                    ->orWhere('location_updated_at', '<', now()->subMinutes($staleAfterMinutes));
            })
            ->with('user')
            ->get()
            // Pedido explícito del usuario: con la ventana de WhatsApp
            // todavía abierta, no se lo desconecta solo por quedarse sin
            // ping de GPS — sigue tratándose como disponible de verdad.
            ->reject(fn (DriverProfile $profile) => $profile->user?->hasActiveWhatsAppSession());

        foreach ($stale as $profile) {
            $profile->update(['is_available' => false]);

            broadcast(new DriverLocationUpdated($profile));

            // Pedido explícito del usuario: acá es la desconexión
            // INVOLUNTARIA (el "bot" la detecta, no el propio conductor) —
            // mismo aviso por WhatsApp, encolado para no frenar el barrido
            // si hay varios conductores stale en la misma corrida.
            NotifyDriverDisconnectedByWhatsApp::dispatch($profile->user_id);
        }

        if ($stale->isNotEmpty()) {
            $this->info("Desconectados {$stale->count()} conductor(es) sin ping reciente.");
        }

        return self::SUCCESS;
    }
}
