<?php

namespace App\Services;

use App\Models\Cooperative;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Despacho secuencial estilo Uber (pedido explícito del usuario): en vez de
 * mandarle una solicitud "a toda la flota disponible" al mismo tiempo, arma
 * la lista de candidatos elegibles de la "bolsa" que el cliente eligió (mi
 * flota / público / ambos), ordenada por cercanía real al origen — el primero
 * de la lista es a quien se le ofrece primero (RideRequestController::store()),
 * el resto queda en `ride_requests.offer_candidate_ids` para la cascada si no
 * responde a tiempo (App\Services\RideDispatchAdvancer, App\Jobs\ExpireRideOffer).
 */
class RideDispatchCandidates
{
    /**
     * Conductores aceptados de una cooperativa, disponibles primero y luego
     * por cercanía al punto de recogida. Los no disponibles quedan fuera de
     * la oferta automática, pero continúan visibles en el panel operativo.
     *
     * @return array<int, int>
     */
    public static function forCooperative(
        Cooperative $cooperative,
        float $originLat,
        float $originLng,
        int $passengerCount = 1,
        bool $needsTrunk = false,
    ): array {
        $busyDriverIds = Ride::query()->where('status', 'in_progress')->pluck('driver_user_id');

        return $cooperative->activeDriverMemberships()
            ->with('driver.driverProfile')
            ->get()
            ->map(fn ($membership) => $membership->driver)
            ->filter(function (User $driver) use ($busyDriverIds, $originLat, $originLng, $passengerCount, $needsTrunk) {
                $profile = $driver->driverProfile;

                return $profile
                    && $profile->driver_type === 'public_transport'
                    && $profile->verification_status === 'approved'
                    && $profile->is_available
                    && ! $profile->isSuspended()
                    && $profile->isReachable($driver->hasActiveWhatsAppSession())
                    && ! $busyDriverIds->contains($driver->id)
                    && $profile->isWithinRangeOf($originLat, $originLng)
                    && $profile->passenger_capacity >= $passengerCount
                    && (! $needsTrunk || $profile->has_trunk);
            })
            ->sortBy(fn (User $driver) => $driver->driverProfile->current_lat !== null && $driver->driverProfile->current_lng !== null
                ? Haversine::distanceKm(
                    $originLat,
                    $originLng,
                    (float) $driver->driverProfile->current_lat,
                    (float) $driver->driverProfile->current_lng,
                )
                : PHP_FLOAT_MAX)
            ->pluck('id')
            ->values()
            ->all();
    }

    /**
     * Reúne los conductores de la "bolsa" elegida (mi flota / público /
     * ambos), sin filtrar todavía por elegibilidad — forPool() y
     * explainEmptyPool() parten de acá para no duplicar esta parte.
     *
     * @return Collection<int, User>
     */
    private static function gatherPoolDrivers(Fleet $fleet, User $client, string $pool): Collection
    {
        $drivers = collect();

        if (in_array($pool, ['fleet', 'both'], true)) {
            // "requests_disabled" (pedido explícito del usuario): un
            // conductor que deshabilitó a este cliente puntual no entra ni
            // siquiera a la bolsa "mi flota"/"ambos".
            $drivers = $drivers->concat(
                $fleet->activeMembers()
                    ->where('requests_disabled', false)
                    ->with('driver.driverProfile')
                    ->get()
                    ->map(fn ($member) => $member->driver)
            );
        }

        if (in_array($pool, ['public', 'both'], true)) {
            $drivers = $drivers->concat(
                DriverProfile::query()
                    ->where('is_public', true)
                    ->where('verification_status', '!=', 'rejected')
                    // Pedido explícito del usuario ("pasarme a cliente"): con
                    // el perfil pausado no entra a la bolsa pública — is_available
                    // en false (que deactivate() ya fuerza) lo saca del
                    // filtro más abajo de todas formas, esto es nomás la
                    // misma defensa en profundidad que el resto de esta clase.
                    ->whereNull('deactivated_at')
                    ->where('user_id', '!=', $client->id)
                    ->with('user')
                    ->get()
                    ->map(fn (DriverProfile $profile) => $profile->user)
            );
        }

        return $drivers->unique('id');
    }

    /**
     * @return array<int, int> ids de conductor, ordenados por cercanía (el más cerca primero)
     */
    public static function forPool(
        Fleet $fleet,
        User $client,
        string $pool,
        float $originLat,
        float $originLng,
        int $passengerCount = 1,
        bool $needsTrunk = false,
    ): array {
        $busyDriverIds = Ride::query()->where('status', 'in_progress')->pluck('driver_user_id');

        return self::gatherPoolDrivers($fleet, $client, $pool)
            ->filter(function (User $driver) use ($busyDriverIds, $originLat, $originLng, $passengerCount, $needsTrunk) {
                $profile = $driver->driverProfile;

                return $profile
                    && $profile->is_available
                    && ! $profile->isSuspended()
                    // Bug reportado por el usuario: sin ping reciente de
                    // ubicación, `is_available` puede seguir en true hasta
                    // que corra el barrido de 2 minutos — no hay que esperar
                    // a eso para dejar de ofrecerle carreras. Excepción
                    // (pedido explícito del usuario): si sigue con la
                    // ventana de WhatsApp abierta, se lo sigue considerando
                    // alcanzable aunque el GPS esté viejo.
                    && $profile->isReachable($driver->hasActiveWhatsAppSession())
                    && ! $busyDriverIds->contains($driver->id)
                    // Zona de cobertura (pedido explícito del usuario): fuera
                    // de rango no entra a la bolsa, así sea de la propia flota.
                    // (isWithinRangeOf ya deja pasar sin ubicación conocida.)
                    && $profile->isWithinRangeOf($originLat, $originLng)
                    // Pedido explícito del usuario: solo conductores cuyo
                    // vehículo alcanza para la cantidad de pasajeros, y con
                    // cajuela si hace falta.
                    && $profile->passenger_capacity >= $passengerCount
                    && (! $needsTrunk || $profile->has_trunk);
            })
            // Al más cercano primero (pedido explícito del usuario). Un
            // conductor sin ubicación conocida todavía (recién se conectó, no
            // reportó GPS) no queda afuera de la bolsa — simplemente no se le
            // puede calcular la distancia, así que se ordena al final.
            ->sortBy(fn (User $driver) => $driver->driverProfile->current_lat !== null && $driver->driverProfile->current_lng !== null
                ? Haversine::distanceKm(
                    $originLat,
                    $originLng,
                    (float) $driver->driverProfile->current_lat,
                    (float) $driver->driverProfile->current_lng,
                )
                : PHP_FLOAT_MAX)
            ->pluck('id')
            ->values()
            ->all();
    }

    /**
     * Bug reportado por el usuario: la cascada del despacho secuencial
     * (App\Services\RideDispatchAdvancer) tomaba el siguiente candidato de
     * la lista armada al principio SIN volver a chequear si seguía elegible
     * — un conductor que se desconectó mientras el primer candidato tenía su
     * turno igual recibía la oferta cuando le tocaba a él. Mismos criterios
     * que forPool() (menos la pertenencia a la bolsa en sí, que no cambia).
     */
    public static function isStillEligible(int $driverUserId, float $originLat, float $originLng, int $passengerCount = 1, bool $needsTrunk = false): bool
    {
        $profile = DriverProfile::query()->with('user')->where('user_id', $driverUserId)->first();

        if (! $profile) {
            return false;
        }

        $isBusy = Ride::query()->where('driver_user_id', $driverUserId)->where('status', 'in_progress')->exists();

        return $profile->is_available
            && ! $profile->isSuspended()
            && $profile->isReachable($profile->user?->hasActiveWhatsAppSession() ?? false)
            && ! $isBusy
            && $profile->isWithinRangeOf($originLat, $originLng)
            && $profile->passenger_capacity >= $passengerCount
            && (! $needsTrunk || $profile->has_trunk);
    }

    private const REASON_NO_DRIVERS = 'no_drivers';

    private const REASON_NONE_CONNECTED = 'none_connected';

    private const REASON_NO_CAPACITY = 'no_capacity';

    private const REASON_OUT_OF_RANGE = 'out_of_range';

    private const REASON_ALL_BUSY = 'all_busy';

    private const REASON_OTHER = 'other';

    /**
     * Recorre la bolsa por etapas (conectado → capacidad → zona → ocupado) y
     * devuelve un código de POR QUÉ quedó vacía, en vez de un mensaje fijo
     * (bug reportado por el usuario: un conductor se veía "disponible" en
     * pantalla pero el mensaje siempre culpaba a "pasajeros/cajuela" aunque
     * el motivo real fuera otro, ej. zona de cobertura). `explainEmptyPool()`
     * traduce esto a texto para el cliente; `isEmptyOnlyBecauseEveryoneIsBusy()`
     * lo usa para decidir si la solicitud puede quedar en espera (pedido
     * explícito del usuario: "puedo dejarla pendiente hasta que uno se
     * desocupe") en vez de rechazarse — solo tiene sentido esperar cuando
     * ESTE es el único motivo, no cuando nadie está conectado o nadie tiene
     * capacidad, ahí esperar no cambia nada.
     */
    private static function poolEmptyReason(
        Fleet $fleet,
        User $client,
        string $pool,
        float $originLat,
        float $originLng,
        int $passengerCount,
        bool $needsTrunk,
    ): string {
        $drivers = self::gatherPoolDrivers($fleet, $client, $pool);

        if ($drivers->isEmpty()) {
            return self::REASON_NO_DRIVERS;
        }

        // Bug reportado por el usuario (contradicción real: la lista de
        // candidatos mostraba a un conductor "En carrera", pero el
        // diagnóstico decía "ninguno conectado"): un conductor EN CARRERA ya
        // demostró que está conectado — hay una Ride real en curso a su
        // nombre — sin importar que su ping GENERAL de ubicación se haya
        // quedado viejo mientras maneja (DriverProfile::isReachable() exige
        // uno reciente, o WhatsApp). Se calcula acá arriba porque hace falta
        // antes del filtro de conectividad, no solo al final — mismo orden
        // de prioridad que ya usa RideRequestController::driverCardData()
        // para el estado que se ve en pantalla (ocupado le gana a "sin ping
        // reciente"), antes estaban al revés en este método.
        $busyDriverIds = Ride::query()->where('status', 'in_progress')->pluck('driver_user_id');

        $reachable = $drivers->filter(function (User $driver) use ($busyDriverIds) {
            $profile = $driver->driverProfile;

            return $profile
                && $profile->is_available
                && ! $profile->isSuspended()
                && ($busyDriverIds->contains($driver->id) || $profile->isReachable($driver->hasActiveWhatsAppSession()));
        });

        if ($reachable->isEmpty()) {
            return self::REASON_NONE_CONNECTED;
        }

        $withCapacity = $reachable->filter(fn (User $driver) => $driver->driverProfile->passenger_capacity >= $passengerCount
            && (! $needsTrunk || $driver->driverProfile->has_trunk));

        if ($withCapacity->isEmpty()) {
            return self::REASON_NO_CAPACITY;
        }

        $inRange = $withCapacity->filter(fn (User $driver) => $driver->driverProfile->isWithinRangeOf($originLat, $originLng));

        if ($inRange->isEmpty()) {
            return self::REASON_OUT_OF_RANGE;
        }

        if ($inRange->every(fn (User $driver) => $busyDriverIds->contains($driver->id))) {
            return self::REASON_ALL_BUSY;
        }

        return self::REASON_OTHER;
    }

    public static function explainEmptyPool(
        Fleet $fleet,
        User $client,
        string $pool,
        float $originLat,
        float $originLng,
        int $passengerCount = 1,
        bool $needsTrunk = false,
    ): string {
        return match (self::poolEmptyReason($fleet, $client, $pool, $originLat, $originLng, $passengerCount, $needsTrunk)) {
            self::REASON_NO_DRIVERS => 'Todavía no tiene conductores en esta bolsa.',
            self::REASON_NONE_CONNECTED => 'Ninguno de sus conductores está conectado ahora mismo.',
            self::REASON_NO_CAPACITY => 'Ninguno de sus conductores conectados tiene lugar para esa cantidad de pasajeros o cajuela.',
            self::REASON_OUT_OF_RANGE => 'Sus conductores disponibles no reciben solicitudes tan lejos de su zona en este momento — pruebe con otro origen o con el directorio público.',
            self::REASON_ALL_BUSY => 'Todos sus conductores disponibles están en otra carrera ahora mismo.',
            default => 'No hay conductores disponibles ahora mismo con esas características.',
        };
    }

    /**
     * Pedido explícito del usuario: si el único motivo por el que la bolsa
     * está vacía es que todos los candidatos elegibles están en otra
     * carrera ahora mismo, la solicitud puede quedar "esperando" en vez de
     * rechazarse — ver RideRequestController::store() y
     * RideDispatchAdvancer::activateNextWaitingRequest().
     */
    public static function isEmptyOnlyBecauseEveryoneIsBusy(
        Fleet $fleet,
        User $client,
        string $pool,
        float $originLat,
        float $originLng,
        int $passengerCount = 1,
        bool $needsTrunk = false,
    ): bool {
        return self::poolEmptyReason($fleet, $client, $pool, $originLat, $originLng, $passengerCount, $needsTrunk) === self::REASON_ALL_BUSY;
    }
}
