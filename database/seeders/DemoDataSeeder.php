<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\ClientCooperative;
use App\Models\Cooperative;
use App\Models\CooperativeDriverMembership;
use App\Models\DriverActivitySession;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\Review;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\User;
use App\Models\WhatsAppSession;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    /**
     * Elenco mínimo para probar la app con una base limpia: un admin y
     * exactamente 4 conductores + 4 clientes (pedido explícito del usuario
     * de resetear todo lo acumulado en pruebas manuales anteriores — sin
     * flotas, carreras, suscripciones, reseñas ni Expresos precargados, para
     * que esos flujos se prueben desde cero de verdad). Todas las cuentas
     * usan la contraseña "password".
     */
    /**
     * Bug real reportado por el usuario ("Reiniciar demo" tiraba
     * `UniqueConstraintViolationException` en el teléfono): los teléfonos de
     * acá son fijos (0990000000-0990000022) a propósito, para que sean
     * fáciles de recordar al probar — pero "Reiniciar demo" (Admin\SystemController)
     * solo borra cuentas `@arka01.test`, nunca cuentas admin ni cuentas
     * reales con otro correo. Si alguna de esas cuentas protegidas ya ocupa
     * uno de estos números (pasó de verdad: una cuenta real de prueba del
     * propio equipo tenía el mismo teléfono que "Pedro Chofer"), el
     * `create()` de más abajo chocaba contra el índice único de `phone` y
     * tiraba abajo TODO el reseteo (está en una sola transacción). Acá se
     * corre al siguiente número libre en vez de romper — se pierde un poco
     * la prolijidad de la numeración, pero nunca la robustez.
     */
    private function demoPhone(string $preferred): string
    {
        $phone = $preferred;
        $suffix = (int) substr($preferred, 4);

        while (User::query()->where('phone', $phone)->exists()) {
            $suffix++;
            $phone = '0990'.str_pad((string) $suffix, 6, '0', STR_PAD_LEFT);
        }

        return $phone;
    }

    /**
     * Bug real detectado (el usuario llevaba varias tandas reportando "no
     * veo conductores en el mapa" incluso después de arreglar las
     * coordenadas): `DriverProfile::isReachable()` exige un ping de
     * ubicación de los últimos `driver_stale_after_minutes` (2 minutos por
     * defecto, ver PricingSetting) — SALVO que el conductor tenga una
     * ventana de WhatsApp abierta. `location_updated_at` de acá arriba solo
     * queda fresco al momento de sembrar; sin esto, cualquiera de estos
     * conductores de prueba se cae de todos los mapas/listas apenas pasan
     * esos 2 minutos desde correr el seeder, aunque coordenadas e
     * `is_available` estén perfectos. Se les abre una ventana larga a
     * propósito, solo para que la demo se pueda seguir probando sin tener
     * que "Reiniciar demo" a cada rato — no toca el umbral real de 2
     * minutos, que sigue aplicando tal cual para conductores de verdad.
     */
    private function keepDriverReachable(User $driver): void
    {
        WhatsAppSession::query()->create([
            'user_id' => $driver->id,
            'opened_at' => now(),
            'expires_at' => now()->addYears(10),
        ]);
    }

    /**
     * Historial abundante pero determinista para revisar visualmente la ficha
     * del conductor: mezcla días, valores, rutas y estados sin depender de Faker.
     */
    private function seedCooperativeDriverHistory(User $driver, Cooperative $cooperative, Fleet $fleet, array $clients, int $driverIndex): void
    {
        $routes = [
            ['6 Callejón 16A NE, Alborada', 'Gral. Calicuchima 428, centro de Guayaquil', 10.1],
            ['Av. Francisco de Orellana, Kennedy Norte', 'Malecón Simón Bolívar, Las Peñas', 7.4],
            ['Mall del Sol, Joaquín Orrantia', 'Urbanización La Joya, Daule', 21.8],
            ['Terminal Terrestre de Guayaquil', 'Puerto Santa Ana', 8.6],
            ['Sauces 6, Guayaquil', 'Plaza Lagos, Samborondón', 14.2],
            ['Urdesa Central, Víctor Emilio Estrada', 'Universidad de Guayaquil', 5.7],
            ['Alborada 10ma etapa', 'Aeropuerto José Joaquín de Olmedo', 6.3],
            ['Mucho Lote 1', 'Mall del Norte, Francisco de Orellana', 9.8],
        ];
        $dayOffsets = [0, 0, 1, 2, 3, 4, 6, 8, 11, 14, 18, 23, 29, 36, 44, 53, 67, 82, 101, 126, 154, 188];

        foreach ($dayOffsets as $index => $daysAgo) {
            $route = $routes[($index + $driverIndex) % count($routes)];
            $client = $clients[($index + $driverIndex) % count($clients)];
            $when = now()->subDays($daysAgo)->setTime(7 + (($index * 2 + $driverIndex) % 13), ($index * 7) % 60);
            $cancelled = in_array($index, [5, 13, 19], true);
            $distance = $route[2] + (($driverIndex % 3) * 0.35);
            $price = round(max(3.25, $distance * (0.46 + (($index % 4) * 0.04))), 2);

            $request = RideRequest::query()->create([
                'fleet_id' => $fleet->id,
                'cooperative_id' => $cooperative->id,
                'cooperative_assignment_status' => 'assigned',
                'client_user_id' => $client->id,
                'driver_user_id' => $driver->id,
                'accepted_by' => $driver->id,
                'origin_lat' => -2.1894 + ($index * 0.0003),
                'origin_lng' => -79.8890 - ($index * 0.0002),
                'origin_address' => $route[0],
                'destination_lat' => -2.1700 - ($index * 0.0002),
                'destination_lng' => -79.9000 + ($index * 0.0002),
                'destination_address' => $route[1],
                'distance_km' => $distance,
                'payment_method' => $index % 3 === 0 ? 'transferencia' : 'efectivo',
                'status' => 'accepted',
                'current_offered_price' => $price,
                'negotiation_round' => 0,
                'last_offer_made_by' => 'client',
                'requested_at' => $when->copy()->subMinutes(8),
                'responded_at' => $when->copy()->subMinutes(6),
                'passenger_count' => 1 + ($index % 3),
                'needs_trunk' => $index % 5 === 0,
                'created_at' => $when->copy()->subMinutes(8),
                'updated_at' => $when,
            ]);

            $ride = Ride::query()->create([
                'ride_request_id' => $request->id,
                'fleet_id' => $fleet->id,
                'client_user_id' => $client->id,
                'driver_user_id' => $driver->id,
                'origin_lat' => $request->origin_lat,
                'origin_lng' => $request->origin_lng,
                'origin_address' => $route[0],
                'destination_lat' => $request->destination_lat,
                'destination_lng' => $request->destination_lng,
                'destination_address' => $route[1],
                'distance_km' => $distance,
                'payment_method' => $request->payment_method,
                'rate_per_km_snapshot' => round($price / $distance, 2),
                'price' => $price,
                'status' => $cancelled ? 'cancelled' : 'completed',
                'started_at' => $when,
                'completed_at' => $cancelled ? null : $when->copy()->addMinutes(18 + ($index % 24)),
                'cancelled_at' => $cancelled ? $when->copy()->addMinutes(4) : null,
                'cancelled_by' => $cancelled ? ($index % 2 ? 'client' : 'driver') : null,
                'cancellation_reason' => $cancelled ? ($index % 2 ? 'Cambio de planes del cliente' : 'Imprevisto del conductor') : null,
                'created_at' => $when,
                'updated_at' => $cancelled ? $when->copy()->addMinutes(4) : $when->copy()->addMinutes(35),
            ]);

            if (! $cancelled) {
                $comments = [
                    'Llegó puntual y la unidad estaba muy limpia.',
                    'Excelente atención, conducción segura y amable.',
                    'Todo muy bien durante el recorrido. Recomendado.',
                    'El conductor conocía muy bien la ruta y llegamos rápido.',
                    'Buen servicio de la cooperativa, volvería a solicitarlo.',
                    'Viaje tranquilo y comunicación clara desde el inicio.',
                ];
                Review::query()->create([
                    'ride_id' => $ride->id,
                    'reviewer_user_id' => $client->id,
                    'reviewee_user_id' => $driver->id,
                    'rating' => [5, 5, 4, 5, 4, 5][$index % 6],
                    'comment' => $comments[($index + $driverIndex) % count($comments)],
                    'created_at' => $ride->completed_at?->copy()->addMinutes(5),
                    'updated_at' => $ride->completed_at?->copy()->addMinutes(5),
                ]);
            }
        }

        // Sesiones cerradas de las últimas cuatro semanas y una sesión abierta hoy.
        for ($day = 1; $day <= 27; $day += 2) {
            $start = now()->subDays($day)->setTime(6 + ($driverIndex % 3), 30);
            DriverActivitySession::query()->create([
                'driver_user_id' => $driver->id,
                'started_at' => $start,
                'last_seen_at' => $start->copy()->addHours(7)->addMinutes(20),
                'ended_at' => $start->copy()->addHours(7)->addMinutes(20),
                'source' => 'demo',
            ]);
        }
        $todayStart = now()->subHours(3 + ($driverIndex % 2));
        DriverActivitySession::query()->create([
            'driver_user_id' => $driver->id,
            'started_at' => $todayStart,
            'last_seen_at' => now(),
            'ended_at' => null,
            'source' => 'demo',
        ]);
    }

    public function run(): void
    {
        $password = Hash::make('password');
        // Guayaquil, no Quito (pedido explícito del usuario, con varias
        // capturas reales de prueba desde Alborada/Urdesa/Samborondón): la
        // ubicación en vivo del navegador de quien prueba la demo cae en
        // Guayaquil, así que los conductores de prueba tienen que estar acá
        // para que el filtro de 15 km de "conductores activos cerca"
        // (DashboardController::nearbyActiveDriversFor()) los encuentre.
        $guayaquilId = City::query()->where('name', 'Guayaquil')->value('id');

        // Pedido explícito del usuario: "reiniciar demo" (Admin\SystemController)
        // ya no borra ninguna cuenta admin — si esta ya existe (de una corrida
        // anterior, o porque el usuario la usa de verdad), no se vuelve a
        // crear. `firstOrCreate` evita el error de correo/teléfono duplicado
        // que tiraría un `create()` sin este chequeo.
        User::query()->firstOrCreate(
            ['email' => 'admin@arka01.test'],
            [
                'name' => 'Admin Arka',
                'phone' => $this->demoPhone('0990000000'),
                'password' => $password,
                'is_admin' => true,
            ]
        );

        // --- Clientes: cuentas puras, sin flota armada todavía (se crea sola
        //     la primera vez que entran a "Mi flota" o al directorio). Con
        //     ubicación de registro en el centro de Quito (pedido explícito
        //     del usuario: "ayudame con conductores demos activos para poder
        //     ilustrar") — así el mapa de Inicio muestra conductores activos
        //     cerca desde el primer vistazo, sin depender de que el
        //     navegador ya haya dado permiso de geolocalización en vivo (ver
        //     DashboardController::nearbyActiveDriversFor()). ---
        $clientes = [
            ['name' => 'Demo Cliente', 'email' => 'cliente@arka01.test', 'phone' => '0990000001'],
            ['name' => 'Otro Cliente', 'email' => 'otro@arka01.test', 'phone' => '0990000002'],
            ['name' => 'Laura Cliente', 'email' => 'laura@arka01.test', 'phone' => '0990000003'],
            ['name' => 'Jorge Cliente', 'email' => 'jorge@arka01.test', 'phone' => '0990000004'],
        ];

        // Guardado por email (pedido explícito del usuario: "agrega flotas y
        // cooperativas con conductores de prueba") — hace falta el modelo de
        // "Demo Cliente" y "Otro Cliente" más abajo, para armarles una flota
        // propia y vincularlos a las cooperativas de prueba.
        $clienteUsers = [];
        foreach ($clientes as $datos) {
            $clienteUsers[$datos['email']] = User::factory()->create([
                'name' => $datos['name'],
                'email' => $datos['email'],
                'phone' => $this->demoPhone($datos['phone']),
                'password' => $password,
                'city_id' => $guayaquilId,
                'registration_lat' => -2.1894,
                'registration_lng' => -79.8890,
            ]);
        }

        // --- Conductores: con perfil ya activado y ubicación en Guayaquil (lo
        //     mínimo para que el mapa y "Pedir carrera" tengan algo que
        //     mostrar sin depender de la geolocalización del navegador). Los
        //     dos primeros con visibilidad pública, para que el directorio
        //     tampoco arranque vacío. ---
        $conductores = [
            ['name' => 'Pedro Chofer', 'email' => 'pedro@arka01.test', 'phone' => '0990000005', 'rate' => 0.45, 'lat' => -2.1943, 'lng' => -79.8920, 'public' => true],
            ['name' => 'Ana Ruedas', 'email' => 'ana@arka01.test', 'phone' => '0990000006', 'rate' => 0.50, 'lat' => -2.1837, 'lng' => -79.9032, 'public' => true],
            ['name' => 'Luis Manejo', 'email' => 'luis@arka01.test', 'phone' => '0990000007', 'rate' => 0.40, 'lat' => -2.2137, 'lng' => -79.9312, 'public' => false],
            ['name' => 'Marta Volante', 'email' => 'marta@arka01.test', 'phone' => '0990000008', 'rate' => 0.55, 'lat' => -2.1737, 'lng' => -79.8812, 'public' => false],
        ];

        foreach ($conductores as $datos) {
            $driver = User::factory()->create([
                'name' => $datos['name'],
                'email' => $datos['email'],
                'phone' => $this->demoPhone($datos['phone']),
                'password' => $password,
                'city_id' => $guayaquilId,
            ]);

            DriverProfile::factory()->for($driver)->create([
                'rate_per_km' => $datos['rate'],
                'is_available' => true,
                'is_public' => $datos['public'],
                'current_lat' => $datos['lat'],
                'current_lng' => $datos['lng'],
                'location_updated_at' => now(),
            ]);
            $this->keepDriverReachable($driver);
        }

        // --- Flota de "Demo Cliente" (pedido explícito del usuario: "agrega
        //     flotas... con conductores de prueba") — para que la categoría
        //     "Conductores de tu flota" del rediseño no arranque vacía al
        //     probar con esa cuenta. Independientes, sin visibilidad pública
        //     (is_public=false): solo aparecen en la bolsa de ESTA flota. ---
        $demoCliente = $clienteUsers['cliente@arka01.test'];
        $fleet = Fleet::query()->create(['owner_user_id' => $demoCliente->id, 'name' => 'Mi flota']);

        $flotaConductores = [
            ['name' => 'Diego Flota', 'email' => 'diego.flota@arka01.test', 'phone' => '0990000009', 'rate' => 0.42, 'lat' => -2.1887, 'lng' => -79.8962],
            ['name' => 'Sara Flota', 'email' => 'sara.flota@arka01.test', 'phone' => '0990000010', 'rate' => 0.48, 'lat' => -2.1987, 'lng' => -79.8862],
            ['name' => 'Iván Flota', 'email' => 'ivan.flota@arka01.test', 'phone' => '0990000011', 'rate' => 0.44, 'lat' => -2.1787, 'lng' => -79.9112],
        ];

        foreach ($flotaConductores as $datos) {
            $driver = User::factory()->create([
                'name' => $datos['name'],
                'email' => $datos['email'],
                'phone' => $this->demoPhone($datos['phone']),
                'password' => $password,
                'city_id' => $guayaquilId,
            ]);

            DriverProfile::factory()->for($driver)->create([
                'rate_per_km' => $datos['rate'],
                'is_available' => true,
                'is_public' => false,
                'current_lat' => $datos['lat'],
                'current_lng' => $datos['lng'],
                'location_updated_at' => now(),
            ]);
            $this->keepDriverReachable($driver);

            FleetMember::query()->create([
                'fleet_id' => $fleet->id,
                'driver_user_id' => $driver->id,
                'added_by' => $demoCliente->id,
                'joined_at' => now(),
            ]);
        }

        // --- Cooperativas (pedido explícito del usuario) — cada una es su
        //     propia cuenta (Cooperative::booted() le pone role='cooperativa'
        //     sola al crearse), aprobada de una vez (forceFill: 'status' no
        //     es masivamente asignable a propósito, ver Admin\CooperativeController)
        //     y vinculada a "Demo Cliente" y "Otro Cliente" para que ambas
        //     cuentas vean la categoría "Cooperativas" con algo adentro.
        //     RideDispatchCandidates::forCooperative() exige driver_type
        //     'public_transport' y verification_status 'approved' — sin eso,
        //     la cooperativa se vería en la lista pero con 0 unidades
        //     despachables de verdad. ---
        $cooperativasData = [
            [
                'name' => 'Cooperativa Amazonas',
                'ruc' => '1790000000001',
                'owner_email' => 'coop.amazonas@arka01.test',
                'owner_phone' => '0990000021',
                'conductores' => [
                    ['name' => 'Rosa Amazonas', 'email' => 'rosa.amazonas@arka01.test', 'phone' => '0990000012', 'rate' => 0.46, 'lat' => -2.1637, 'lng' => -79.9062],
                    ['name' => 'Pablo Amazonas', 'email' => 'pablo.amazonas@arka01.test', 'phone' => '0990000013', 'rate' => 0.41, 'lat' => -2.2067, 'lng' => -79.8762],
                    ['name' => 'Elena Amazonas', 'email' => 'elena.amazonas@arka01.test', 'phone' => '0990000014', 'rate' => 0.52, 'lat' => -2.1807, 'lng' => -79.9212],
                ],
            ],
            [
                'name' => 'Cooperativa Pichincha',
                'ruc' => '1790000000002',
                'owner_email' => 'coop.pichincha@arka01.test',
                'owner_phone' => '0990000022',
                'conductores' => [
                    ['name' => 'Mario Pichincha', 'email' => 'mario.pichincha@arka01.test', 'phone' => '0990000015', 'rate' => 0.39, 'lat' => -2.2187, 'lng' => -79.9112],
                    ['name' => 'Carla Pichincha', 'email' => 'carla.pichincha@arka01.test', 'phone' => '0990000016', 'rate' => 0.47, 'lat' => -2.1737, 'lng' => -79.8912],
                    ['name' => 'Óscar Pichincha', 'email' => 'oscar.pichincha@arka01.test', 'phone' => '0990000017', 'rate' => 0.43, 'lat' => -2.1967, 'lng' => -79.8812],
                ],
            ],
        ];

        foreach ($cooperativasData as $cooperativeIndex => $datos) {
            $owner = User::factory()->create([
                'name' => $datos['name'],
                'email' => $datos['owner_email'],
                'phone' => $this->demoPhone($datos['owner_phone']),
                'password' => $password,
                'city_id' => $guayaquilId,
            ]);

            $cooperative = Cooperative::query()->create([
                'user_id' => $owner->id,
                'name' => $datos['name'],
                'legal_name' => $datos['name'],
                'ruc' => $datos['ruc'],
                'main_address' => 'Av. de prueba, Guayaquil',
                'city_id' => $guayaquilId,
                'province' => 'Guayas',
                'phone' => $this->demoPhone($datos['owner_phone']),
                'email' => $datos['owner_email'],
                'declared_driver_count' => count($datos['conductores']),
                'declared_unit_count' => count($datos['conductores']),
            ]);
            $cooperative->forceFill([
                'status' => 'approved',
                'submitted_at' => now(),
                'reviewed_at' => now(),
            ])->save();

            foreach ([$clienteUsers['cliente@arka01.test'], $clienteUsers['otro@arka01.test']] as $client) {
                ClientCooperative::query()->create([
                    'client_user_id' => $client->id,
                    'cooperative_id' => $cooperative->id,
                ]);
            }

            foreach ($datos['conductores'] as $driverIndex => $datosConductor) {
                $driver = User::factory()->create([
                    'name' => $datosConductor['name'],
                    'email' => $datosConductor['email'],
                    'phone' => $this->demoPhone($datosConductor['phone']),
                    'password' => $password,
                    'city_id' => $guayaquilId,
                ]);

                DriverProfile::factory()->for($driver)->create([
                    'rate_per_km' => $datosConductor['rate'],
                    'driver_type' => 'public_transport',
                    'is_available' => true,
                    'is_public' => false,
                    'current_lat' => $datosConductor['lat'],
                    'current_lng' => $datosConductor['lng'],
                    'location_updated_at' => now(),
                ]);
                $this->keepDriverReachable($driver);

                CooperativeDriverMembership::query()->create([
                    'cooperative_id' => $cooperative->id,
                    'driver_user_id' => $driver->id,
                    'invited_by_user_id' => $owner->id,
                    'status' => 'accepted',
                    'responded_at' => now(),
                ]);

                $this->seedCooperativeDriverHistory(
                    $driver,
                    $cooperative,
                    $fleet,
                    array_values($clienteUsers),
                    ($cooperativeIndex * 3) + $driverIndex,
                );
            }
        }

        // --- Conductores públicos extra (pedido explícito del usuario:
        //     "coloca conductores públicos también") — sin flota ni
        //     cooperativa, solo visibles en el Directorio y en la categoría
        //     "Conductores públicos"/"Todos" al pedir una carrera. ---
        $publicosExtra = [
            ['name' => 'Nina Pública', 'email' => 'nina.publica@arka01.test', 'phone' => '0990000018', 'rate' => 0.38, 'lat' => -2.1587, 'lng' => -79.8992],
            ['name' => 'Tomás Público', 'email' => 'tomas.publico@arka01.test', 'phone' => '0990000019', 'rate' => 0.53, 'lat' => -2.2087, 'lng' => -79.8862],
            ['name' => 'Vale Pública', 'email' => 'vale.publica@arka01.test', 'phone' => '0990000020', 'rate' => 0.49, 'lat' => -2.1867, 'lng' => -79.9262],
        ];

        foreach ($publicosExtra as $datos) {
            $driver = User::factory()->create([
                'name' => $datos['name'],
                'email' => $datos['email'],
                'phone' => $this->demoPhone($datos['phone']),
                'password' => $password,
                'city_id' => $guayaquilId,
            ]);

            DriverProfile::factory()->for($driver)->create([
                'rate_per_km' => $datos['rate'],
                'is_available' => true,
                'is_public' => true,
                'current_lat' => $datos['lat'],
                'current_lng' => $datos['lng'],
                'location_updated_at' => now(),
            ]);
            $this->keepDriverReachable($driver);
        }

        $this->command->info('Base reseteada. Todas las cuentas usan la contraseña "password":');
        $this->command->table(['Rol', 'Email'], [
            ['Admin', 'admin@arka01.test'],
            ['Cliente', 'cliente@arka01.test'],
            ['Cliente', 'otro@arka01.test'],
            ['Cliente', 'laura@arka01.test'],
            ['Cliente', 'jorge@arka01.test'],
            ['Conductor (público)', 'pedro@arka01.test'],
            ['Conductor (público)', 'ana@arka01.test'],
            ['Conductor', 'luis@arka01.test'],
            ['Conductor', 'marta@arka01.test'],
            ['Conductor (flota de Demo Cliente)', 'diego.flota@arka01.test / sara.flota@arka01.test / ivan.flota@arka01.test'],
            ['Cooperativa (dueño)', 'coop.amazonas@arka01.test'],
            ['Conductor (Coop. Amazonas)', 'rosa.amazonas / pablo.amazonas / elena.amazonas @arka01.test'],
            ['Cooperativa (dueño)', 'coop.pichincha@arka01.test'],
            ['Conductor (Coop. Pichincha)', 'mario.pichincha / carla.pichincha / oscar.pichincha @arka01.test'],
            ['Conductor (público extra)', 'nina.publica / tomas.publico / vale.publica @arka01.test'],
        ]);
    }
}
