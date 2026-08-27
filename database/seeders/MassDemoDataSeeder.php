<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\ClientCooperative;
use App\Models\Cooperative;
use App\Models\CooperativeDriverMembership;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\Review;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\User;
use App\Models\WhatsAppSession;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Seeder de VOLUMEN para demos (pedido explícito del usuario: "quiero que me
 * crees muchos datos semillas, muchos conductores 300 y 600 clientes y 50
 * cooperativas... que cada uno tenga carreras y todos los conductores tenga
 * clientes y todos los clientes tengan conductores y que muchos conductores
 * esten en diferente cooperativas, crea nombres reales y diferentes
 * configuraciones y estados, activos, en carrera, disponibles, mas
 * cercanos... la contraseña para los usuarios coloca para todos 123").
 *
 * A PROPÓSITO es un seeder aparte de DemoDataSeeder: el usuario ya pidió una
 * vez mantener ESE mínimo (1 admin + 4 clientes + 4 conductores, sin datos
 * relacionales) para que "Reiniciar demo" (Admin\SystemController) siga
 * siendo una base limpia y rápida — ver memoria "Cuentas demo mínimas". Este
 * seeder solo se corre a mano cuando hace falta una base grande para
 * mostrar la plataforma; nunca se engancha a "Reiniciar demo" ni a
 * `db:seed` por defecto.
 *
 * Se corre con: `php artisan db:seed --class=MassDemoDataSeeder`
 * (agrega datos nuevos sobre lo que ya exista — no borra nada; para partir
 * de una base limpia primero "Reiniciar demo" desde /admin/sistema, o
 * `migrate:fresh --seed`).
 */
class MassDemoDataSeeder extends Seeder
{
    private const DRIVER_COUNT = 300;

    private const CLIENT_COUNT = 600;

    private const COOPERATIVE_COUNT = 50;

    /** Nombres ecuatorianos reales y variados — no genéricos de faker en inglés. */
    private const FIRST_NAMES_MALE = [
        'Carlos', 'José', 'Luis', 'Juan', 'Miguel', 'Pedro', 'Diego', 'Andrés', 'Fernando', 'Roberto',
        'Manuel', 'Francisco', 'Jorge', 'Ricardo', 'Eduardo', 'Alberto', 'Raúl', 'Iván', 'Marco', 'Xavier',
        'Freddy', 'Wilson', 'Klever', 'Byron', 'Patricio', 'Vinicio', 'Rodrigo', 'Danilo', 'Efraín', 'Segundo',
        'Ángel', 'Bolívar', 'Washington', 'Galo', 'Hugo', 'Ramiro', 'Édison', 'Néstor', 'Cristian', 'Alexis',
    ];

    private const FIRST_NAMES_FEMALE = [
        'María', 'Ana', 'Carmen', 'Rosa', 'Gabriela', 'Diana', 'Patricia', 'Verónica', 'Sandra', 'Mónica',
        'Cecilia', 'Elena', 'Paola', 'Johanna', 'Karina', 'Silvia', 'Lorena', 'Fernanda', 'Cristina', 'Andrea',
        'Alexandra', 'Jessica', 'Tatiana', 'Nube', 'Mercedes', 'Blanca', 'Norma', 'Gladys', 'Consuelo', 'Piedad',
        'Gloria', 'Doris', 'Narcisa', 'Yolanda', 'Marisol', 'Katherine', 'Estefanía', 'Priscila', 'Liliana', 'Rocío',
    ];

    private const LAST_NAMES = [
        'Guamán', 'Chávez', 'Vargas', 'Morales', 'Cevallos', 'Zambrano', 'Vera', 'Andrade', 'Salazar', 'Rivera',
        'Torres', 'Mendoza', 'Villacís', 'Naranjo', 'Ortiz', 'Espinoza', 'Cedeño', 'Bravo', 'Suárez', 'Palacios',
        'Quinde', 'Yépez', 'Chiriboga', 'Freire', 'Cabezas', 'Loor', 'Muñoz', 'Sánchez', 'Jiménez', 'Pazmiño',
        'Herrera', 'Villamar', 'Reyes', 'Solórzano', 'Bermeo', 'Tapia', 'Guerrero', 'Merino', 'Cárdenas', 'Aguilar',
        'Zapata', 'Cadena', 'Guevara', 'Ponce', 'Barros', 'Coello', 'Delgado', 'Erazo', 'Farinango', 'Gaona',
    ];

    private const ROUTE_PLACES = [
        'Centro', 'Zona norte', 'Zona sur', 'Vía a la costa', 'Urbanización Los Ceibos', 'Sector La Pradera',
        'Av. principal', 'Redondel central', 'Parque central', 'Terminal terrestre', 'Mercado municipal',
        'Ciudadela Kennedy', 'Barrio San Andrés', 'Vía perimetral', 'Sector El Recreo', 'Malecón',
    ];

    private const REVIEW_COMMENTS = [
        'Llegó puntual y la unidad estaba muy limpia.',
        'Excelente atención, conducción segura y amable.',
        'Todo muy bien durante el recorrido. Recomendado.',
        'El conductor conocía muy bien la ruta y llegamos rápido.',
        'Buen servicio, volvería a solicitarlo sin duda.',
        'Viaje tranquilo y comunicación clara desde el inicio.',
        'Muy amable y respetuoso, se sintió un viaje seguro.',
        'Precio justo y sin contratiempos en el camino.',
    ];

    private array $driverIds = [];

    private array $clientIds = [];

    private array $cooperativeIds = [];

    /** @var array<int, int> cooperative_id => id del User dueño (para invited_by_user_id) */
    private array $cooperativeOwnerId = [];

    /** @var array<int, array{id:int,lat:float,lng:float}> */
    private array $driverLocations = [];

    /** @var array<int, array{id:int,lat:float,lng:float}> */
    private array $clientLocations = [];

    /** Puntos acumulados por conductor (App\Models\DriverProfile::total_points, ver RideController). */
    private array $pointsByDriver = [];

    public function run(): void
    {
        $start = microtime(true);
        $password = Hash::make('123');

        $cities = City::query()->get(['id', 'name', 'lat', 'lng']);
        $guayaquil = $cities->firstWhere('name', 'Guayaquil');
        $otherCities = $cities->reject(fn (City $c) => $c->id === $guayaquil->id)->values();

        DB::transaction(function () use ($password, $guayaquil, $otherCities) {
            $this->createDrivers($password, $guayaquil, $otherCities);
            $this->createClients($password, $guayaquil, $otherCities);
            $this->createCooperativesAndAffiliations($password, $guayaquil, $otherCities);
            $edges = $this->createFleetMemberships();
            $this->createClientCooperativeFollows();
            $this->createRideHistory($edges);
            $this->flushDriverPoints();
        });

        $seconds = round(microtime(true) - $start, 1);
        $this->command->info("Listo en {$seconds}s: ".self::DRIVER_COUNT.' conductores, '.self::CLIENT_COUNT.' clientes, '.self::COOPERATIVE_COUNT.' cooperativas. Contraseña para todas las cuentas nuevas: "123".');
    }

    private function randomFullName(): string
    {
        $first = random_int(0, 1) === 1
            ? self::FIRST_NAMES_FEMALE[array_rand(self::FIRST_NAMES_FEMALE)]
            : self::FIRST_NAMES_MALE[array_rand(self::FIRST_NAMES_MALE)];

        return $first.' '.self::LAST_NAMES[array_rand(self::LAST_NAMES)].' '.self::LAST_NAMES[array_rand(self::LAST_NAMES)];
    }

    /**
     * Concentra la mayoría en Guayaquil (pedido explícito del usuario en una
     * vuelta anterior: la ubicación en vivo de quien prueba la demo cae ahí,
     * así que "más cercanos" necesita masa crítica en esa zona) y reparte el
     * resto entre otras ciudades reales del Ecuador, para variedad.
     *
     * @return array{0:int,1:float,2:float}
     */
    private function pickCityAndCoords(City $guayaquil, Collection $otherCities): array
    {
        $city = random_int(1, 100) <= 65 ? $guayaquil : $otherCities->random();
        $jitterLat = random_int(-450, 450) / 10000;
        $jitterLng = random_int(-450, 450) / 10000;

        return [$city->id, (float) $city->lat + $jitterLat, (float) $city->lng + $jitterLng];
    }

    /** @param  array<int|string, int>  $weights */
    private function weightedPick(array $weights): int|string
    {
        $total = array_sum($weights);
        $roll = random_int(1, $total);
        $cumulative = 0;
        foreach ($weights as $value => $weight) {
            $cumulative += $weight;
            if ($roll <= $cumulative) {
                return $value;
            }
        }

        return array_key_first($weights);
    }

    /**
     * Conductores con estados variados (pedido explícito del usuario:
     * "diferentes configuraciones y estados, activos, en carrera,
     * disponibles"): la mayoría aprobados y disponibles ahora mismo, una
     * porción desconectada, y un cupo chico en revisión/rechazado — para que
     * el panel de verificaciones del admin tampoco arranque vacío.
     */
    private function createDrivers(string $password, City $guayaquil, Collection $otherCities): void
    {
        for ($i = 0; $i < self::DRIVER_COUNT; $i++) {
            [$cityId, $lat, $lng] = $this->pickCityAndCoords($guayaquil, $otherCities);

            $driver = User::factory()->create([
                'name' => $this->randomFullName(),
                'password' => $password,
                'city_id' => $cityId,
                'registration_lat' => $lat,
                'registration_lng' => $lng,
            ]);

            $verificationStatus = $this->weightedPick(['approved' => 85, 'pending' => 10, 'rejected' => 5]);
            $isApproved = $verificationStatus === 'approved';

            // Entre los aprobados: la mayoría disponible ahora mismo, el
            // resto desconectado — un pendiente/rechazado nunca puede estar
            // disponible (DriverProfile::canBecomeAvailable()).
            $isAvailable = $isApproved && $this->weightedPick(['yes' => 75, 'no' => 25]) === 'yes';
            $locationFresh = $isAvailable ? now() : now()->subDays(random_int(2, 20));

            DriverProfile::factory()->for($driver)->create([
                'rate_per_km' => round(random_int(35, 70) / 100, 2),
                'is_available' => $isAvailable,
                'is_public' => $isApproved && random_int(1, 100) <= 45,
                'verification_status' => $verificationStatus,
                'current_lat' => $lat,
                'current_lng' => $lng,
                'location_updated_at' => $locationFresh,
            ]);

            if ($isAvailable) {
                // Mismo criterio que DemoDataSeeder::keepDriverReachable(): sin
                // esto, DriverProfile::isStale() los tira del mapa apenas
                // pasen los minutos de umbral desde que se corrió el seeder.
                WhatsAppSession::query()->create([
                    'user_id' => $driver->id,
                    'opened_at' => now(),
                    'expires_at' => now()->addYears(10),
                ]);
            }

            $this->driverIds[] = $driver->id;
            $this->driverLocations[$driver->id] = ['lat' => $lat, 'lng' => $lng];

            if (($i + 1) % 50 === 0) {
                $this->command->info('Conductores: '.($i + 1).'/'.self::DRIVER_COUNT);
            }
        }
    }

    private function createClients(string $password, City $guayaquil, Collection $otherCities): void
    {
        for ($i = 0; $i < self::CLIENT_COUNT; $i++) {
            [$cityId, $lat, $lng] = $this->pickCityAndCoords($guayaquil, $otherCities);

            $client = User::factory()->create([
                'name' => $this->randomFullName(),
                'password' => $password,
                'city_id' => $cityId,
                'registration_lat' => $lat,
                'registration_lng' => $lng,
            ]);

            $this->clientIds[] = $client->id;
            $this->clientLocations[$client->id] = ['lat' => $lat, 'lng' => $lng];

            if (($i + 1) % 100 === 0) {
                $this->command->info('Clientes: '.($i + 1).'/'.self::CLIENT_COUNT);
            }
        }
    }

    /**
     * 50 cooperativas (pedido explícito del usuario) — cada una es su propia
     * cuenta aprobada de una vez. Un subconjunto de conductores se afilia,
     * repartido entre TODAS las cooperativas ("muchos conductores esten en
     * diferente cooperativas") — un conductor sigue siendo, además, parte de
     * las flotas de sus clientes: son capas independientes (sección 4 del
     * manual de bondades).
     */
    private function createCooperativesAndAffiliations(string $password, City $guayaquil, Collection $otherCities): void
    {
        for ($i = 0; $i < self::COOPERATIVE_COUNT; $i++) {
            [$cityId, $lat, $lng] = $this->pickCityAndCoords($guayaquil, $otherCities);
            $cityName = City::query()->whereKey($cityId)->value('name');
            $name = 'Cooperativa '.self::LAST_NAMES[array_rand(self::LAST_NAMES)].' '.($i + 1);

            $owner = User::factory()->create([
                'name' => $name,
                'password' => $password,
                'city_id' => $cityId,
                'registration_lat' => $lat,
                'registration_lng' => $lng,
            ]);

            $cooperative = Cooperative::query()->create([
                'user_id' => $owner->id,
                'name' => $name,
                'legal_name' => $name,
                // Prefijo/rango distinto al de DemoDataSeeder (que ya usa
                // '1790000000001'/'02' para sus 2 cooperativas de prueba) —
                // este seeder se corre SOBRE una base que puede ya tenerlas.
                'ruc' => '179'.str_pad((string) (500000 + $i), 10, '0', STR_PAD_LEFT),
                'main_address' => 'Av. de prueba, '.$cityName,
                // Pedido/manual: "presencia física en el mapa" — sin esto,
                // RideRequestController/RideDispatchCandidates::forCooperative()
                // no puede calcular distancia real ni encontrarla como
                // "cooperativa más cercana".
                'stand_lat' => $lat,
                'stand_lng' => $lng,
                'city_id' => $cityId,
                'province' => 'Guayas',
                'declared_driver_count' => 0,
                'declared_unit_count' => 0,
            ]);
            $cooperative->forceFill([
                'status' => 'approved',
                'submitted_at' => now(),
                'reviewed_at' => now(),
            ])->save();

            $this->cooperativeIds[] = $cooperative->id;
            $this->cooperativeOwnerId[$cooperative->id] = $owner->id;
        }

        // ~45% de los conductores se afilia a alguna cooperativa, repartidos
        // uno por uno entre las 50 (round-robin sobre una lista barajada) —
        // así "muchos conductores" terminan en cooperativas DISTINTAS entre
        // sí, en vez de amontonarse siempre en la primera.
        $coopDriverPool = collect($this->driverIds)->shuffle()->take((int) (self::DRIVER_COUNT * 0.45))->values();
        $publicTransportDriverIds = [];

        foreach ($coopDriverPool as $index => $driverId) {
            $cooperativeId = $this->cooperativeIds[$index % count($this->cooperativeIds)];

            CooperativeDriverMembership::query()->create([
                'cooperative_id' => $cooperativeId,
                'driver_user_id' => $driverId,
                'invited_by_user_id' => $this->cooperativeOwnerId[$cooperativeId],
                'status' => 'accepted',
                'responded_at' => now(),
            ]);

            $publicTransportDriverIds[] = $driverId;
        }

        if ($publicTransportDriverIds !== []) {
            // RideDispatchCandidates::forCooperative() solo despacha
            // conductores driver_type='public_transport' — sin esto, estos
            // conductores aparecerían afiliados pero nunca serían
            // despachables de verdad por su cooperativa.
            DriverProfile::query()->whereIn('user_id', $publicTransportDriverIds)->update(['driver_type' => 'public_transport']);
        }

        DB::table('cooperatives')->whereIn('id', $this->cooperativeIds)->update(['declared_driver_count' => 0]);
        foreach ($this->cooperativeIds as $cooperativeId) {
            $count = CooperativeDriverMembership::query()->where('cooperative_id', $cooperativeId)->count();
            DB::table('cooperatives')->where('id', $cooperativeId)->update(['declared_driver_count' => $count, 'declared_unit_count' => $count]);
        }
    }

    /**
     * Cada cliente sigue 0-2 cooperativas de su red (ClientCooperative) —
     * para que la categoría "Cooperativas" no arranque vacía en la mayoría
     * de las cuentas demo.
     */
    private function createClientCooperativeFollows(): void
    {
        foreach ($this->clientIds as $clientId) {
            $followCount = $this->weightedPick([0 => 15, 1 => 65, 2 => 20]);
            if ($followCount === 0) {
                continue;
            }

            $chosen = collect($this->cooperativeIds)->shuffle()->take($followCount);
            foreach ($chosen as $cooperativeId) {
                ClientCooperative::query()->create([
                    'client_user_id' => $clientId,
                    'cooperative_id' => $cooperativeId,
                ]);
            }
        }
    }

    /**
     * Arma la flota de cada cliente (pedido explícito del usuario: "todos
     * los conductores tenga clientes y todos los clientes tengan
     * conductores"). El reparto round-robin sobre los 300 conductores
     * barajados garantiza que CADA UNO aparezca como asignación principal de
     * exactamente 2 clientes (600 clientes / 300 conductores) antes de
     * sumar conductores extra al azar — así ningún conductor queda sin
     * cliente y ningún cliente queda sin conductor, sin depender de la
     * suerte del azar puro.
     *
     * @return array<int, array{client_user_id:int, driver_user_id:int}>
     */
    private function createFleetMemberships(): array
    {
        $fleetIdByClient = [];
        foreach ($this->clientIds as $clientId) {
            $fleetIdByClient[$clientId] = Fleet::query()->create(['owner_user_id' => $clientId, 'name' => 'Mi flota'])->id;
        }

        $shuffledDrivers = collect($this->driverIds)->shuffle()->values();
        $edges = [];

        foreach ($this->clientIds as $clientIndex => $clientId) {
            $primaryDriverId = $shuffledDrivers[$clientIndex % self::DRIVER_COUNT];
            $driversForThisClient = [$primaryDriverId];

            $extraCount = $this->weightedPick([0 => 55, 1 => 25, 2 => 13, 3 => 7]);
            if ($extraCount > 0) {
                $extra = collect($this->driverIds)
                    ->reject(fn (int $id) => $id === $primaryDriverId)
                    ->shuffle()
                    ->take($extraCount);
                array_push($driversForThisClient, ...$extra->all());
            }

            foreach (array_unique($driversForThisClient) as $driverId) {
                FleetMember::query()->create([
                    'fleet_id' => $fleetIdByClient[$clientId],
                    'driver_user_id' => $driverId,
                    'added_by' => $clientId,
                    'joined_at' => now()->subDays(random_int(0, 150)),
                ]);

                $edges[] = ['client_user_id' => $clientId, 'driver_user_id' => $driverId, 'fleet_id' => $fleetIdByClient[$clientId]];
            }
        }

        $this->command->info('Flotas armadas: '.count($edges).' vínculos cliente-conductor.');

        return $edges;
    }

    /**
     * Historial de carreras por cada vínculo cliente-conductor (pedido
     * explícito del usuario: "que cada uno tenga carreras"), más un cupo
     * fijo de carreras EN CURSO ahora mismo ("en carrera") para poder
     * mostrar ese estado en vivo durante una demo.
     *
     * @param  array<int, array{client_user_id:int, driver_user_id:int, fleet_id:int}>  $edges
     */
    private function createRideHistory(array $edges): void
    {
        $total = count($edges);
        $processed = 0;

        // Bug real detectado al armar este seeder: RideRequest/Ride/Review
        // no tienen 'created_at'/'updated_at' en $fillable (por diseño,
        // Eloquent los administra solo) — mandarlos igual en ::create()
        // NO tira error, pero Eloquent los descarta en silencio y graba la
        // hora actual en su lugar, dejando TODO el historial (pensado para
        // repartirse en los últimos 120 días) amontonado en "ahora mismo".
        // unguard() los deja fijar la fecha real que se les pasa —
        // Eloquent respeta cualquier atributo que ya llegue "dirty" al
        // guardar, así que no la vuelve a pisar.
        Model::unguard();

        foreach ($edges as $edge) {
            $rideCount = $this->weightedPick([1 => 40, 2 => 35, 3 => 15, 4 => 10]);

            for ($n = 0; $n < $rideCount; $n++) {
                $this->seedOneRide($edge['client_user_id'], $edge['driver_user_id'], $edge['fleet_id'], liveNow: false);
            }

            $processed++;
            if ($processed % 200 === 0) {
                $this->command->info("Historial de carreras: {$processed}/{$total} vínculos procesados.");
            }
        }

        // Cupo fijo "en carrera" (pedido explícito del usuario): 25
        // conductores disponibles y aprobados quedan con un viaje realmente
        // EN CURSO (Ride::status = 'in_progress') — eso es justo lo que
        // RideDispatchCandidates usa para marcarlos ocupados, igual que un
        // conductor real a mitad de un viaje.
        $liveDriverIds = DriverProfile::query()
            ->where('is_available', true)
            ->where('verification_status', 'approved')
            ->whereIn('user_id', array_unique(array_column($edges, 'driver_user_id')))
            ->inRandomOrder()
            ->limit(25)
            ->pluck('user_id');

        foreach ($liveDriverIds as $driverId) {
            $edge = collect($edges)->firstWhere('driver_user_id', $driverId);
            if (! $edge) {
                continue;
            }

            $this->seedOneRide($edge['client_user_id'], $edge['driver_user_id'], $edge['fleet_id'], liveNow: true);
        }

        Model::reguard();

        $this->command->info('Carreras "en curso" para la demo: '.count($liveDriverIds));
    }

    private function seedOneRide(int $clientId, int $driverId, int $fleetId, bool $liveNow): void
    {
        $originLat = $this->clientLocations[$clientId]['lat'];
        $originLng = $this->clientLocations[$clientId]['lng'];
        $distanceKm = round(random_int(15, 250) / 10, 1);
        $bearingLat = random_int(-100, 100) / 100;
        $bearingLng = random_int(-100, 100) / 100;
        $destLat = $originLat + ($distanceKm / 111) * $bearingLat;
        $destLng = $originLng + ($distanceKm / 111) * $bearingLng;

        $rate = DriverProfile::query()->where('user_id', $driverId)->value('rate_per_km') ?? 0.45;
        $price = round(max(1.75, $distanceKm * $rate), 2);
        $originAddress = self::ROUTE_PLACES[array_rand(self::ROUTE_PLACES)];
        $destinationAddress = self::ROUTE_PLACES[array_rand(self::ROUTE_PLACES)];

        if ($liveNow) {
            $requestedAt = now()->subMinutes(random_int(10, 45));
            $phase = $this->weightedPick(['heading' => 40, 'arrived' => 30, 'picked_up' => 30]);
        } else {
            $requestedAt = now()->subDays(random_int(0, 120))->subHours(random_int(0, 23))->subMinutes(random_int(0, 59));
            $phase = null;
        }

        $request = RideRequest::query()->create([
            'fleet_id' => $fleetId,
            'client_user_id' => $clientId,
            'driver_user_id' => $driverId,
            'accepted_by' => $driverId,
            'origin_lat' => $originLat,
            'origin_lng' => $originLng,
            'origin_address' => $originAddress,
            'destination_lat' => $destLat,
            'destination_lng' => $destLng,
            'destination_address' => $destinationAddress,
            'distance_km' => $distanceKm,
            'payment_method' => random_int(1, 3) === 1 ? 'transferencia' : 'efectivo',
            'status' => 'accepted',
            'current_offered_price' => $price,
            'negotiation_round' => 0,
            'last_offer_made_by' => 'client',
            'requested_at' => $requestedAt,
            'responded_at' => $requestedAt->copy()->addMinutes(2),
            'passenger_count' => random_int(1, 4),
            'needs_trunk' => random_int(1, 5) === 1,
            'created_at' => $requestedAt,
            'updated_at' => $requestedAt,
        ]);

        if ($liveNow) {
            $startedAt = $requestedAt->copy()->addMinutes(3);
            Ride::query()->create([
                'ride_request_id' => $request->id,
                'fleet_id' => $fleetId,
                'client_user_id' => $clientId,
                'driver_user_id' => $driverId,
                'origin_lat' => $originLat,
                'origin_lng' => $originLng,
                'origin_address' => $originAddress,
                'destination_lat' => $destLat,
                'destination_lng' => $destLng,
                'destination_address' => $destinationAddress,
                'distance_km' => $distanceKm,
                'payment_method' => $request->payment_method,
                'rate_per_km_snapshot' => $rate,
                'price' => $price,
                'status' => 'in_progress',
                'started_at' => $startedAt,
                'arrived_at' => in_array($phase, ['arrived', 'picked_up'], true) ? $startedAt->copy()->addMinutes(8) : null,
                'picked_up_at' => $phase === 'picked_up' ? $startedAt->copy()->addMinutes(11) : null,
                'created_at' => $startedAt,
                'updated_at' => now(),
            ]);

            return;
        }

        $cancelled = $this->weightedPick(['completed' => 82, 'cancelled' => 18]) === 'cancelled';
        $startedAt = $requestedAt->copy()->addMinutes(5);
        $completedAt = $startedAt->copy()->addMinutes(random_int(12, 55));

        $ride = Ride::query()->create([
            'ride_request_id' => $request->id,
            'fleet_id' => $fleetId,
            'client_user_id' => $clientId,
            'driver_user_id' => $driverId,
            'origin_lat' => $originLat,
            'origin_lng' => $originLng,
            'origin_address' => $originAddress,
            'destination_lat' => $destLat,
            'destination_lng' => $destLng,
            'destination_address' => $destinationAddress,
            'distance_km' => $distanceKm,
            'payment_method' => $request->payment_method,
            'rate_per_km_snapshot' => $rate,
            'price' => $price,
            'points_earned' => $cancelled ? 0 : ($distanceKm >= 5 ? 2 : 1),
            'status' => $cancelled ? 'cancelled' : 'completed',
            'started_at' => $startedAt,
            'arrived_at' => $startedAt->copy()->addMinutes(6),
            'picked_up_at' => $cancelled ? null : $startedAt->copy()->addMinutes(9),
            'completed_at' => $cancelled ? null : $completedAt,
            'cancelled_at' => $cancelled ? $startedAt->copy()->addMinutes(4) : null,
            'cancelled_by' => $cancelled ? ($this->weightedPick(['client' => 1, 'driver' => 1])) : null,
            'cancellation_reason' => $cancelled ? 'Cambio de planes' : null,
            'created_at' => $requestedAt,
            'updated_at' => $cancelled ? $startedAt->copy()->addMinutes(4) : $completedAt,
        ]);

        if (! $cancelled) {
            $this->pointsByDriver[$driverId] = ($this->pointsByDriver[$driverId] ?? 0) + $ride->points_earned;

            if (random_int(1, 100) <= 85) {
                Review::query()->create([
                    'ride_id' => $ride->id,
                    'reviewer_user_id' => $clientId,
                    'reviewee_user_id' => $driverId,
                    'rating' => $this->weightedPick([5 => 55, 4 => 30, 3 => 10, 2 => 4, 1 => 1]),
                    'comment' => self::REVIEW_COMMENTS[array_rand(self::REVIEW_COMMENTS)],
                    'created_at' => $completedAt->copy()->addMinutes(5),
                    'updated_at' => $completedAt->copy()->addMinutes(5),
                ]);
            }
        }
    }

    /** DriverProfile::total_points se acumula con increment() en producción (RideController) — acá se vuelca de una sola vez al final. */
    private function flushDriverPoints(): void
    {
        foreach ($this->pointsByDriver as $driverId => $points) {
            DriverProfile::query()->where('user_id', $driverId)->update(['total_points' => $points]);
        }
    }
}
