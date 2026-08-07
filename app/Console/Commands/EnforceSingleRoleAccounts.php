<?php

namespace App\Console\Commands;

use App\Models\Fleet;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Arreglo único de cuentas que quedaron con doble rol (cliente y conductor a
 * la vez) de cuando eso todavía era posible — sección 3.1 pasó a ser "cada
 * cuenta es cliente o conductor, nunca las dos" y esas cuentas ya no se
 * pueden crear de nuevas, pero las que ya existían (ej. la demo "Pedro
 * Chofer", que terminó con una flota propia por visitar /flotas) necesitan
 * arreglo manual una vez.
 *
 * Decisión del dueño del proyecto: se conserva el rol de conductor y se
 * quita el de cliente, de forma automática, sin confirmar cuenta por cuenta.
 * Salvedad de seguridad: si alguna de las flotas de esa cuenta ya tiene
 * carreras reales (`rides`/`ride_requests`), NO se borra — borrarla
 * destruiría ese historial por el cascade de la migración, así que se
 * reporta aparte para revisión manual en vez de perder datos en silencio.
 */
class EnforceSingleRoleAccounts extends Command
{
    protected $signature = 'app:enforce-single-role';

    protected $description = 'Corrige cuentas que quedaron siendo cliente y conductor a la vez, priorizando el rol de conductor';

    public function handle(): int
    {
        $dualRoleUsers = User::query()
            ->whereHas('driverProfile')
            ->whereHas('fleets')
            ->get();

        if ($dualRoleUsers->isEmpty()) {
            $this->info('No hay cuentas con doble rol — nada que corregir.');

            return self::SUCCESS;
        }

        $fixed = 0;
        $needsReview = [];

        foreach ($dualRoleUsers as $user) {
            $fleets = Fleet::query()->where('owner_user_id', $user->id)->get();
            $anyDeleted = false;

            foreach ($fleets as $fleet) {
                $hasHistory = RideRequest::query()->where('fleet_id', $fleet->id)->exists()
                    || Ride::query()->where('fleet_id', $fleet->id)->exists();

                if ($hasHistory) {
                    $needsReview[] = "  - {$user->email} (user #{$user->id}): flota #{$fleet->id} \"{$fleet->name}\" tiene carreras reales, no se tocó.";

                    continue;
                }

                $fleet->delete();
                $anyDeleted = true;
            }

            if (! $anyDeleted) {
                continue;
            }

            $clientSubscription = $user->activeSubscription('client');
            if ($clientSubscription) {
                $clientSubscription->update([
                    'status' => 'expired',
                    'note' => trim(($clientSubscription->note ? $clientSubscription->note.' — ' : '').'Cancelada: la cuenta pasó a ser solo conductor (app:enforce-single-role).'),
                ]);
            }

            $fixed++;
            $this->line("Arreglada: {$user->email} (user #{$user->id}) — se quedó como conductor.");
        }

        $this->info("Listo: {$fixed} cuenta(s) arreglada(s) automáticamente.");

        if ($needsReview !== []) {
            $this->warn('Pendiente de revisión manual (tienen historial real de carreras):');
            foreach ($needsReview as $line) {
                $this->line($line);
            }
        }

        return self::SUCCESS;
    }
}
