<?php

namespace App\Console\Commands;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Pedido explícito del usuario: quiere ver cómo se ve la pantalla cuando un
 * cliente tiene muchos conductores (~20), con estados variados (disponible,
 * ocupado, desconectado, "fantasma"/sin ping reciente) — para probar el
 * diseño con datos parecidos a producción, no con los 4 conductores de
 * siempre. A propósito NO es parte de DemoDataSeeder (que el usuario pidió
 * mantener mínimo) — se corre a mano una sola vez:
 *
 *   php artisan demo:seed-many-drivers
 *
 * No toca los conductores que ya existen (pedido explícito: "deja los
 * conductores que están ya") — solo agrega cuentas nuevas.
 */
class SeedManyDemoDrivers extends Command
{
    protected $signature = 'demo:seed-many-drivers {--per-fleet=20 : Cuántos conductores nuevos agregar a CADA flota existente}';

    protected $description = 'Crea conductores de demo variados (disponible/ocupado/desconectado/fantasma) y los asigna a cada flota existente';

    /** Misma contraseña para todos los conductores que crea este comando (pedido explícito del usuario). */
    private const PASSWORD = 'Demo1234';

    private const FIRST_NAMES = [
        'Carlos', 'María', 'José', 'Ana', 'Luis', 'Carmen', 'Pedro', 'Rosa', 'Juan', 'Laura',
        'Miguel', 'Sofía', 'Diego', 'Valentina', 'Andrés', 'Camila', 'Jorge', 'Daniela', 'Fernando', 'Gabriela',
    ];

    private const LAST_NAMES = [
        'Vera', 'Zambrano', 'Mendoza', 'Chávez', 'Rodríguez', 'Cedeño', 'Alvarado', 'Loor', 'Macías', 'Bravo',
        'Salazar', 'Reyes', 'Intriago', 'Pincay', 'Delgado', 'Cevallos', 'Palacios', 'Quiñónez', 'Solórzano', 'Vélez',
    ];

    private int $counter = 0;

    public function handle(): int
    {
        $perFleet = (int) $this->option('per-fleet');

        $fleets = Fleet::query()->with('owner')->get();

        if ($fleets->isEmpty()) {
            $this->warn('No hay flotas todavía — no hay a quién asignarle conductores.');

            return self::SUCCESS;
        }

        // Empieza el conteo de teléfonos/correos después de lo que ya exista,
        // para no chocar con ninguna cuenta real (pedido explícito del
        // usuario: no tocar lo que ya está).
        $this->counter = User::query()->count() * 10;

        $multiflota = SubscriptionPlan::query()->where('owner_type', 'client')->where('code', 'multiflota')->first();

        $created = 0;

        foreach ($fleets as $fleet) {
            $this->ensureEnoughCapacity($fleet, $perFleet, $multiflota);

            for ($i = 0; $i < $perFleet; $i++) {
                $this->makeDemoDriver($fleet, $i);
                $created++;
            }

            $this->info("Flota #{$fleet->id} ({$fleet->owner->name}): +{$perFleet} conductores.");
        }

        $this->info("Listo — {$created} conductores de demo creados. Contraseña para todos: ".self::PASSWORD);

        return self::SUCCESS;
    }

    /**
     * Pedido explícito del usuario: "sube la capacidad del plan que tengan
     * si es necesario" — sin esto, el cupo del plan Gratis/Plus del cliente
     * frenaría la asignación antes de llegar a los ~20 pedidos.
     */
    private function ensureEnoughCapacity(Fleet $fleet, int $perFleet, ?SubscriptionPlan $multiflota): void
    {
        if (! $multiflota) {
            return;
        }

        $needed = $fleet->activeMembers()->count() + $perFleet;
        $current = $fleet->owner->activeSubscription('client');
        $currentLimit = $current?->custom_max_drivers_per_fleet ?? $current?->plan->max_drivers_per_fleet;

        if ($currentLimit !== null && $currentLimit < $needed) {
            Subscription::query()->create([
                'user_id' => $fleet->owner_user_id,
                'subscription_plan_id' => $multiflota->id,
                'status' => 'active',
                'started_at' => now(),
            ]);
        }
    }

    private function makeDemoDriver(Fleet $fleet, int $index): void
    {
        $this->counter++;

        $name = self::FIRST_NAMES[array_rand(self::FIRST_NAMES)].' '
            .self::LAST_NAMES[array_rand(self::LAST_NAMES)].' '
            .self::LAST_NAMES[array_rand(self::LAST_NAMES)];

        $user = User::query()->create([
            'name' => $name,
            'email' => "conductor-demo-{$this->counter}@arka01.test",
            'phone' => '+593'.str_pad((string) (900000 + $this->counter), 9, '0', STR_PAD_LEFT),
            'password' => Hash::make(self::PASSWORD),
            'phone_verified_at' => now(),
            'email_verified_at' => now(),
        ]);

        // Estados variados (pedido explícito del usuario: "activos,
        // inactivos, activos" — repartidos en 4 categorías parejas para
        // poder ver los 4 casos reales en pantalla):
        //   0: disponible de verdad ahora mismo
        //   1: disponible, pero en carrera ahora mismo ("ocupado")
        //   2: desconectado a propósito (is_available = false)
        //   3: "fantasma" — is_available sigue en true, pero sin ping
        //      reciente (isReachable() lo trata como desconectado igual)
        $state = $index % 4;

        DriverProfile::factory()->for($user)->create([
            // Bug corregido: el estado 3 ("fantasma") tiene que seguir
            // marcado is_available=true en la base — lo que lo saca de la
            // lista es el ping viejo (isReachable()), no el propio flag.
            'is_available' => in_array($state, [0, 1, 3], true),
            'location_updated_at' => $state === 3 ? now()->subMinutes(10) : now(),
            'verification_status' => 'approved',
            'current_lat' => -2.17 + fake()->randomFloat(4, -0.05, 0.05),
            'current_lng' => -79.90 + fake()->randomFloat(4, -0.05, 0.05),
        ]);

        FleetMember::query()->create([
            'fleet_id' => $fleet->id,
            'driver_user_id' => $user->id,
            'added_by' => $fleet->owner_user_id,
            'joined_at' => now(),
        ]);

        if ($state === 1) {
            $rideRequest = RideRequest::factory()->create([
                'fleet_id' => $fleet->id,
                'client_user_id' => $fleet->owner_user_id,
                'driver_user_id' => $user->id,
                'status' => 'accepted',
                'accepted_by' => $user->id,
            ]);

            Ride::factory()->create([
                'ride_request_id' => $rideRequest->id,
                'fleet_id' => $fleet->id,
                'client_user_id' => $fleet->owner_user_id,
                'driver_user_id' => $user->id,
                'status' => 'in_progress',
            ]);
        }
    }
}
