<?php

namespace App\Http\Controllers;

use App\Models\AdBanner;
use App\Models\Cooperative;
use App\Models\CooperativeDriverMembership;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetInvitation;
use App\Models\FleetMember;
use App\Models\Review;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\User;
use App\Services\FrequentPlaces;
use App\Services\Haversine;
use App\Services\WhatsAppConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Inicio" (sección 9.5): antes era una pantalla de bienvenida sin datos.
 * Ahora suma indicadores reales del rol activo de la cuenta (cliente o
 * conductor, nunca los dos — ver App\Models\User::isClient()).
 */
class DashboardController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        // Compatibilidad con versiones anteriores del frontend: las
        // coordenadas nunca deben permanecer en una URL, porque terminan en
        // historial, capturas, analytics y logs del servidor. Si llega un
        // enlace antiguo, guardamos temporalmente una coordenada válida en
        // la sesión y redirigimos de inmediato al Dashboard limpio.
        if ($request->hasAny(['lat', 'lng'])) {
            $validated = $request->validate([
                'lat' => ['required_with:lng', 'numeric', 'between:-90,90'],
                'lng' => ['required_with:lat', 'numeric', 'between:-180,180'],
            ]);

            if (isset($validated['lat'], $validated['lng'])) {
                $this->storeLocationInSession($request, (float) $validated['lat'], (float) $validated['lng']);
            }

            return redirect()->route('dashboard');
        }

        $user = $request->user();

        if ($user->isCooperative()) {
            return redirect()->route('cooperative.dashboard');
        }
        $userId = $user->id;

        $driverStats = null;
        $upcomingTrips = null;
        $inviteCode = null;
        $earningsSparkline = null;
        $driverFleetIds = null;
        $whatsappSession = null;

        if ($user->isDriver()) {
            $cooperative = CooperativeDriverMembership::activeCooperativeFor($userId);

            // Flotas donde deshabilité las solicitudes del cliente dueño
            // (pedido explícito del usuario) — mismo criterio que RideController::index().
            $disabledFleetIds = FleetMember::query()
                ->where('driver_user_id', $userId)
                ->where('requests_disabled', true)
                ->pluck('fleet_id');

            $driverStats = [
                'cooperative' => $cooperative ? [
                    'id' => $cooperative->id,
                    'name' => $cooperative->name,
                    'logo_url' => $cooperative->logo_url,
                ] : null,
                'active_clients' => FleetMember::query()->where('driver_user_id', $userId)->whereNull('left_at')->count(),
                'pending_requests' => RideRequest::pendingIncomingFor($userId)
                    ->get()
                    // Zona de cobertura + cliente deshabilitado (pedido
                    // explícito del usuario): que el número de la tarjeta
                    // "Solicitudes" coincida con lo que de verdad va a ver en
                    // /carreras.
                    ->filter(fn (RideRequest $rideRequest) => $rideRequest->driver_user_id === $userId
                        || (! $disabledFleetIds->contains($rideRequest->fleet_id)
                            && ($user->driverProfile?->isWithinRangeOf((float) $rideRequest->origin_lat, (float) $rideRequest->origin_lng) ?? true)))
                    ->count(),
                'completed_rides' => Ride::query()->where('driver_user_id', $userId)->where('status', 'completed')->count(),
                // El tablero diferencia producción de hoy, del mes y el
                // histórico. Antes solo enviaba el total y la interfaz lo
                // presentaba debajo de "Ingresos de hoy", lo que podía
                // hacer creer que todas esas carreras correspondían al día.
                'completed_rides_today' => Ride::query()
                    ->where('driver_user_id', $userId)
                    ->where('status', 'completed')
                    ->whereDate('completed_at', today())
                    ->count(),
                'completed_rides_this_month' => Ride::query()
                    ->where('driver_user_id', $userId)
                    ->where('status', 'completed')
                    ->whereMonth('completed_at', now()->month)
                    ->whereYear('completed_at', now()->year)
                    ->count(),
                // Pedido explícito del usuario: "que le muestre también la
                // cantidad de $ que ha hecho en el día y lo que lleva del
                // mes" — mismo criterio que earnings_this_month, de abajo,
                // solo que acotado al día de hoy.
                'earnings_today' => (float) Ride::query()
                    ->where('driver_user_id', $userId)
                    ->where('status', 'completed')
                    ->whereDate('completed_at', today())
                    ->sum('price'),
                'earnings_this_month' => (float) Ride::query()
                    ->where('driver_user_id', $userId)
                    ->where('status', 'completed')
                    ->whereMonth('completed_at', now()->month)
                    ->whereYear('completed_at', now()->year)
                    ->sum('price'),
                'rating' => round((float) $user->reviewsReceived()->avg('rating'), 1),
                'review_count' => $user->reviewsReceived()->count(),
                // La UI recibe la misma decisión que aplica el endpoint de
                // ubicación. No mostramos el switch encendido si el perfil
                // quedó pendiente/rechazado aunque exista un valor antiguo.
                'can_connect' => (bool) $user->driverProfile?->canBecomeAvailable(),
                'connection_block_reason' => $user->driverProfile?->availabilityBlockReason(),
                'is_available' => (bool) ($user->driverProfile?->is_available && $user->driverProfile?->canBecomeAvailable()),
                // Reporte del usuario ("Luis aparece desconectado si está en
                // línea"): `is_available` es la intención del conductor
                // (prendió el switch), pero el resto de la app (roster de
                // "Mi flota" del cliente, candidatos al despacho) exige
                // además un ping de ubicación reciente — ver
                // DriverProfile::isReachable(). Sin este dato, el propio
                // conductor no tenía forma de saber que, aunque él se ve
                // "Disponible", sus clientes ya lo ven desconectado.
                'is_reachable' => (bool) $user->driverProfile?->isReachable($user->hasActiveWhatsAppSession()),
                // Se reportó que invitar a un conductor a la flota no le
                // avisaba nada en su Inicio (solo se veía si por casualidad
                // ya estaba parado en "Mis clientes de confianza") — con este
                // número, la tarjeta "Mis clientes" puede mostrar el mismo
                // tipo de badge que ya tiene "Solicitudes".
                'pending_invitations' => FleetInvitation::query()
                    ->where('driver_user_id', $userId)
                    ->where('status', 'pending')
                    ->count(),
                // Pedido explícito del usuario: mostrar en Inicio, de un
                // vistazo, la tarifa que el conductor tiene declarada — con
                // un link directo al formulario para corregirla, sin tener
                // que ir a buscarlo en Mi perfil.
                'rate_per_km' => $user->driverProfile?->rate_per_km !== null ? (float) $user->driverProfile->rate_per_km : null,
                'minimum_fare' => $user->driverProfile?->minimum_fare !== null ? (float) $user->driverProfile->minimum_fare : null,
            ];

            $upcomingTrips = $this->upcomingTripsFor($userId, asDriver: true);
            $inviteCode = $user->driverProfile?->invite_code;
            $earningsSparkline = $this->earningsSparklineFor($userId);
            // Para que el inicio pueda suscribirse en vivo a solicitudes nuevas
            // "a toda la flota" (consideración agregada al alcance) — mismo
            // criterio que RideController::index(), que ya arma esta lista para
            // la pantalla de Carreras. Las dirigidas llegan por el canal
            // personal, que el frontend ya conoce sin necesidad de esta lista.
            $driverFleetIds = FleetMember::query()
                ->where('driver_user_id', $userId)
                ->whereNull('left_at')
                ->pluck('fleet_id');

            // Pedido explícito del usuario: al activarse desde el botón de
            // Inicio, si todavía no tiene la ventana de 24h de WhatsApp
            // abierta, se le ofrece conectarla ahí mismo (ver
            // DriverAvailabilityToggle.vue) — mismo dato que ya se muestra
            // en Driver/Profile.vue.
            $session = $user->currentWhatsAppSession();
            $whatsappSession = $session ? [
                'status' => $session->status(),
                'expires_at' => $session->expires_at->toIso8601String(),
            ] : null;
        }

        $fleetDrivers = null;
        $nearbyDrivers = null;
        $targetFleetId = null;
        $frequentPlaces = [];
        $savedRoutes = [];
        $homeInitialCenter = null;
        $adminStats = null;

        // Pedido explícito del usuario ("indicadores en el dashboard...
        // personas registradas, Pasajeros, conductores, cooperativas, esta
        // semana. este mes. hoy"): tarjetas rápidas en Inicio con enlace
        // directo al módulo correspondiente — antes solo existían los
        // totales sin desglose de App\Http\Controllers\Admin\MetricsController.
        if ($user->isAdmin()) {
            $today = now()->startOfDay();
            $week = now()->startOfWeek();
            $month = now()->startOfMonth();

            $countsFor = function ($query) use ($today, $week, $month) {
                return [
                    'today' => (clone $query)->where('created_at', '>=', $today)->count(),
                    'week' => (clone $query)->where('created_at', '>=', $week)->count(),
                    'month' => (clone $query)->where('created_at', '>=', $month)->count(),
                    'total' => (clone $query)->count(),
                ];
            };

            $adminStats = [
                // Igual que Admin\ClientController/Admin\DriverController: el
                // filtro directo por la columna 'role' evita cargar cada
                // driverProfile/cooperative solo para descartarlo.
                'people' => $countsFor(User::query()),
                'clients' => $countsFor(User::query()->where('role', 'cliente')),
                'drivers' => $countsFor(User::query()->where('role', 'conductor')),
                'cooperatives' => $countsFor(Cooperative::query()),
            ];
        }

        if ($user->isClient()) {
            // Mismo criterio que FleetController::index()/RideRequestController:
            // la primera flota se crea sola al primer uso, para no pedir un paso
            // extra de "crear flota" — acá hace falta un id de flota real para
            // que el botón "Agregar" de "Conductores cerca" tenga adónde invitar.
            $fleet = Fleet::query()->where('owner_user_id', $userId)->orderBy('id')->first()
                ?? Fleet::query()->create(['owner_user_id' => $userId, 'name' => 'Mi flota']);

            $fleetDrivers = $this->fleetDriversFor($request, $fleet);
            $nearbyDrivers = $this->nearbyActiveDriversFor($request, $user, $fleet);
            $targetFleetId = $fleet->id;
            $upcomingTrips = $this->upcomingTripsFor($userId, asDriver: false);
            // Rediseño UX (pedido explícito del usuario, guiado por
            // ARKA01_Rediseno_UX_Flujo_Carreras.md): buscador "¿A dónde vas?"
            // arriba de Inicio — mismos datos que ya usaba Ride/Request.vue
            // (favoritos automáticos y rutas guardadas con alias), ver
            // App\Services\FrequentPlaces (compartido entre los dos
            // controllers para no duplicar la consulta).
            $frequentPlaces = FrequentPlaces::forClient($userId);
            $savedRoutes = $user->savedRoutes()->latest()->get();

            // Bug real reportado por el usuario ("porque no centra la
            // ubicación actual"): el mapa de Inicio arrancaba centrado en
            // Quito (el valor por defecto de FleetMap.vue) hasta que la
            // geolocalización en vivo del navegador resolviera — sin
            // permiso, o mientras el usuario no respondía el aviso del
            // navegador, se quedaba ahí sin ningún indicio de qué pasó. Con
            // la ubicación de registro ya guardada (si la hay), el mapa
            // arranca centrado en la ciudad real del cliente de una — la
            // ubicación en vivo, si se consigue, la corrige después con más
            // precisión (ver Dashboard.vue, onMounted).
            if ($user->registration_lat !== null && $user->registration_lng !== null) {
                $homeInitialCenter = ['lat' => (float) $user->registration_lat, 'lng' => (float) $user->registration_lng];
            }
        }

        return Inertia::render('Dashboard', [
            'driverStats' => $driverStats,
            'fleetDrivers' => $fleetDrivers,
            'nearbyDrivers' => $nearbyDrivers,
            'targetFleetId' => $targetFleetId,
            'upcomingTrips' => $upcomingTrips,
            'inviteCode' => $inviteCode,
            'earningsSparkline' => $earningsSparkline,
            'driverFleetIds' => $driverFleetIds,
            // Módulo de publicidad (pedido explícito del usuario): un lugar
            // estratégico que no interfiera con la navegación — el inicio, que
            // ya es la pantalla más visitada, debajo del saludo.
            'adBanners' => AdBanner::query()->visible()->orderBy('sort_order')->get(),
            'whatsappSession' => $whatsappSession,
            'whatsappBusinessNumber' => WhatsAppConfig::businessNumber(),
            'frequentPlaces' => $frequentPlaces,
            'savedRoutes' => $savedRoutes,
            'homeInitialCenter' => $homeInitialCenter,
            'adminStats' => $adminStats,
        ]);
    }

    /**
     * Recibe la ubicación por POST para que no forme parte de la URL.
     * Se conserva solo en la sesión y caduca rápidamente; no modifica el
     * perfil ni crea un historial permanente de ubicaciones.
     */
    public function updateLocation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $this->storeLocationInSession($request, (float) $validated['lat'], (float) $validated['lng']);

        return response()->json(status: 204);
    }

    /**
     * Tarjetas de "Mi flota" en el inicio (consideración agregada al
     * alcance): mismo criterio de estado que `RideRequestController` — en
     * carrera pesa más que "disponible" prendido, porque no puede tomar una
     * segunda a la vez. Suma calificación y distancia, mismo patrón que
     * `nearbyDriversFor()`, para que la tarjeta se vea completa como en el
     * directorio público.
     *
     * @return Collection<int, array{user_id: int, name: string, avatar_url: string|null, status: string, average_rating: float|null, review_count: int, distance_km: float|null}>
     */
    private function fleetDriversFor(Request $request, Fleet $fleet): Collection
    {
        [$lat, $lng] = $this->locationFromSession($request);

        $busyDriverIds = Ride::query()->where('status', 'in_progress')->pluck('driver_user_id');

        $members = $fleet->activeMembers()->with('driver.driverProfile')->limit(8)->get();

        $ratings = Review::query()
            ->whereIn('reviewee_user_id', $members->pluck('driver_user_id'))
            ->selectRaw('reviewee_user_id, avg(rating) as avg_rating, count(*) as review_count')
            ->groupBy('reviewee_user_id')
            ->get()
            ->keyBy('reviewee_user_id');

        return $members
            ->map(function (FleetMember $member) use ($busyDriverIds, $ratings, $lat, $lng) {
                $rating = $ratings->get($member->driver_user_id);
                $profile = $member->driver->driverProfile;
                $distanceKm = ($lat !== null && $lng !== null && $profile?->current_lat !== null)
                    ? Haversine::distanceKm($lat, $lng, (float) $profile->current_lat, (float) $profile->current_lng)
                    : null;

                return [
                    'user_id' => $member->driver_user_id,
                    'name' => $member->driver->full_name,
                    'avatar_url' => $member->driver->avatar_url,
                    'status' => match (true) {
                        $busyDriverIds->contains($member->driver_user_id) => 'busy',
                        // Bug reportado por el usuario: sin ping reciente,
                        // `is_available` puede seguir en true hasta el
                        // barrido de 2 minutos — salvo que siga alcanzable
                        // por WhatsApp (DriverProfile::isReachable()).
                        (bool) $profile?->is_available && $profile->isReachable($member->driver->hasActiveWhatsAppSession()) => 'available',
                        default => 'offline',
                    },
                    'average_rating' => $rating ? round((float) $rating->avg_rating, 1) : null,
                    'review_count' => $rating->review_count ?? 0,
                    'distance_km' => $distanceKm,
                ];
            })
            ->values();
    }

    /**
     * "Próximos viajes" del inicio (consideración agregada al alcance, mockup
     * provisto por el usuario): no existe el concepto de agendar un viaje a
     * futuro (`RideRequest::requested_at` siempre es "ahora"), así que acá se
     * muestran los viajes REALES en curso — pendientes/negociando (todavía sin
     * confirmar) y ya aceptados (`Ride` en `in_progress`) — no fechas
     * inventadas. Mismo criterio de "a quién le llegan" que
     * `RideController::index()`, pero acotado a una vista previa de 3.
     *
     * @return Collection<int, array{status: string, origin_label: string, destination_label: string, price: float, counterpart_name: string}>
     */
    private function upcomingTripsFor(int $userId, bool $asDriver): Collection
    {
        $pendingQuery = RideRequest::query()->whereIn('status', ['pending', 'negotiating']);

        if ($asDriver) {
            $pendingQuery->where(function ($query) use ($userId) {
                $query->where('driver_user_id', $userId)
                    ->orWhere(function ($query) use ($userId) {
                        $query->whereNull('driver_user_id')
                            ->whereIn('fleet_id', function ($sub) use ($userId) {
                                $sub->select('fleet_id')
                                    ->from('fleet_members')
                                    ->where('driver_user_id', $userId)
                                    ->whereNull('left_at');
                            });
                    })
                    ->orWhere(function ($query) use ($userId) {
                        $query->where('status', 'negotiating')->where('negotiating_driver_user_id', $userId);
                    });
            });
        } else {
            $pendingQuery->where('client_user_id', $userId);
        }

        $pending = $pendingQuery->with(['client', 'driver', 'originSector', 'destinationSector'])
            ->latest('requested_at')
            ->limit(3)
            ->get()
            ->map(fn (RideRequest $rideRequest) => [
                'status' => 'pending',
                'origin_label' => $rideRequest->originSector->name ?? $rideRequest->origin_address ?? 'Origen',
                'destination_label' => $rideRequest->destinationSector->name ?? $rideRequest->destination_address ?? 'Destino',
                'price' => (float) $rideRequest->current_offered_price,
                'counterpart_name' => $asDriver ? $rideRequest->client->name : ($rideRequest->driver->name ?? 'Toda la flota'),
                'at' => $rideRequest->requested_at,
            ]);

        $confirmed = Ride::query()
            ->where('status', 'in_progress')
            ->where($asDriver ? 'driver_user_id' : 'client_user_id', $userId)
            ->with(['client', 'driver', 'originSector', 'destinationSector'])
            ->latest('started_at')
            ->limit(3)
            ->get()
            ->map(fn (Ride $ride) => [
                'status' => 'confirmed',
                'ride_id' => $ride->id,
                'origin_label' => $ride->originSector->name ?? $ride->origin_address ?? 'Origen',
                'destination_label' => $ride->destinationSector->name ?? $ride->destination_address ?? 'Destino',
                'price' => (float) $ride->price,
                'counterpart_name' => $asDriver ? $ride->client->name : $ride->driver->name,
                'at' => $ride->started_at,
            ]);

        // Aceptadas a partir de una solicitud PROGRAMADA (consideración
        // agregada al alcance) pero que el conductor todavía no arrancó — no
        // son "en curso" (ver Ride::isScheduledAndNotStarted()).
        $scheduled = Ride::query()
            ->where('status', 'scheduled')
            ->where($asDriver ? 'driver_user_id' : 'client_user_id', $userId)
            ->with(['client', 'driver', 'originSector', 'destinationSector', 'rideRequest'])
            ->limit(3)
            ->get()
            ->map(fn (Ride $ride) => [
                'status' => 'scheduled',
                'ride_id' => $ride->id,
                'origin_label' => $ride->originSector->name ?? $ride->origin_address ?? 'Origen',
                'destination_label' => $ride->destinationSector->name ?? $ride->destination_address ?? 'Destino',
                'price' => (float) $ride->price,
                'counterpart_name' => $asDriver ? $ride->client->name : $ride->driver->name,
                'scheduled_at' => $ride->rideRequest?->scheduled_at,
                'round_trip' => (bool) $ride->round_trip,
                'at' => $ride->rideRequest?->scheduled_at ?? $ride->created_at,
            ]);

        // Orden por cercanía a "ahora" (no por fecha descendente a secas): una
        // solicitud recién pedida y una carrera programada para dentro de un
        // rato importan más que una programada para dentro de tres días.
        return $pending->concat($confirmed)->concat($scheduled)
            ->sortBy(fn (array $trip) => now()->diffInSeconds($trip['at']))
            ->take(3)
            ->map(fn (array $trip) => collect($trip)->except('at')->all())
            ->values();
    }

    /**
     * Ganancias por día de los últimos 14 días (consideración agregada al
     * alcance, mockup del inicio del conductor) — un array simple de montos
     * para dibujar un sparkline en el frontend, sin librería de gráficos ni
     * tabla nueva.
     *
     * @return array<int, float>
     */
    private function earningsSparklineFor(int $userId): array
    {
        $since = now()->subDays(13)->startOfDay();

        $byDay = Ride::query()
            ->where('driver_user_id', $userId)
            ->where('status', 'completed')
            ->where('completed_at', '>=', $since)
            ->selectRaw('DATE(completed_at) as day, SUM(price) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        return collect(range(0, 13))
            ->map(fn ($i) => (float) ($byDay[$since->copy()->addDays($i)->toDateString()] ?? 0))
            ->all();
    }

    /**
     * Conductores activos para el mapa de Inicio (pedido explícito del
     * usuario: "colocar todos los conductores sean de su flota o
     * cooperativas... o [públicos], que aparezcan solo los activos y cerca
     * del origen de la carrera") — unión de las tres bolsas que ya existen
     * al pedir una carrera (App\Services\RideDispatchCandidates: flota,
     * cooperativas de la red del cliente, y público), pero acá con fines
     * ilustrativos nomás (marcadores en el mapa, sin tocar el despacho real).
     *
     * "Activos" = disponibles y alcanzables ahora mismo (mismo criterio que
     * el despacho real: `isReachable()`, no en una carrera en curso). "Cerca
     * del origen" = con `current_lat/lng` conocido y, si el cliente tiene
     * ubicación (en vivo guardada temporalmente en sesión o, si no, la del registro), dentro
     * de 15 km — sin coordenadas del cliente no hay "cerca" que calcular, así
     * que no se filtra por distancia (se listan igual, sin orden geográfico).
     * La distancia se manda en km solo para que el frontend calcule minutos
     * (Utils/eta.js) — nunca se muestra el km exacto (pedido explícito del
     * usuario sobre privacidad, ver Ride/Request.vue y Directory/Index.vue).
     *
     * @return Collection<int, array{user_id: int, name: string, avatar_url: string|null, lat: float, lng: float, distance_km: float|null, source: string}>
     */
    private function nearbyActiveDriversFor(Request $request, User $user, Fleet $fleet): Collection
    {
        [$lat, $lng] = $this->locationFromSession($request);

        if ($lat === null && $lng === null && $user->registration_lat !== null && $user->registration_lng !== null) {
            $lat = (float) $user->registration_lat;
            $lng = (float) $user->registration_lng;
        }

        $fleetDriverIds = $fleet->activeMembers()->pluck('driver_user_id');

        $cooperativeIds = Cooperative::query()
            ->where('status', 'approved')
            ->whereNull('suspended_at')
            ->whereHas('clientLinks', fn ($query) => $query->where('client_user_id', $user->id))
            ->pluck('id');

        $cooperativeDriverIds = CooperativeDriverMembership::query()
            ->whereIn('cooperative_id', $cooperativeIds)
            ->where('status', 'accepted')
            ->whereNull('ended_at')
            ->pluck('driver_user_id');

        $publicDriverIds = DriverProfile::query()->where('is_public', true)->pluck('user_id');

        $candidateIds = $fleetDriverIds->concat($cooperativeDriverIds)->concat($publicDriverIds)
            ->unique()
            ->reject(fn ($id) => $id === $user->id);

        $busyDriverIds = Ride::query()->where('status', 'in_progress')->pluck('driver_user_id');

        $profiles = DriverProfile::query()
            ->whereIn('user_id', $candidateIds)
            ->where('is_available', true)
            ->where('verification_status', '!=', 'rejected')
            ->whereNull('deactivated_at')
            ->whereNotNull('current_lat')
            ->whereNotNull('current_lng')
            ->with('user')
            ->get()
            ->filter(fn (DriverProfile $profile) => ! $busyDriverIds->contains($profile->user_id)
                && $profile->isReachable($profile->user->hasActiveWhatsAppSession()));

        $entries = $profiles->map(function (DriverProfile $profile) use ($lat, $lng, $fleetDriverIds, $cooperativeDriverIds) {
            $distanceKm = ($lat !== null && $lng !== null)
                ? Haversine::distanceKm($lat, $lng, (float) $profile->current_lat, (float) $profile->current_lng)
                : null;

            return [
                'user_id' => $profile->user_id,
                'name' => $profile->user->name,
                'avatar_url' => $profile->user->avatar_url,
                'lat' => (float) $profile->current_lat,
                'lng' => (float) $profile->current_lng,
                'distance_km' => $distanceKm,
                'source' => match (true) {
                    $fleetDriverIds->contains($profile->user_id) => 'fleet',
                    $cooperativeDriverIds->contains($profile->user_id) => 'cooperative',
                    default => 'public',
                },
            ];
        });

        if ($lat !== null && $lng !== null) {
            $entries = $entries->filter(fn (array $entry) => $entry['distance_km'] <= 15);
        }

        return $entries->sortBy('distance_km')->take(20)->values();
    }

    private function storeLocationInSession(Request $request, float $lat, float $lng): void
    {
        $request->session()->put('dashboard_location', [
            'lat' => $lat,
            'lng' => $lng,
            'captured_at' => now()->timestamp,
        ]);
    }

    /** @return array{0: float|null, 1: float|null} */
    private function locationFromSession(Request $request): array
    {
        $location = $request->session()->get('dashboard_location');

        if (! is_array($location)
            || ! isset($location['lat'], $location['lng'], $location['captured_at'])
            || ((int) $location['captured_at']) < now()->subMinutes(5)->timestamp) {
            $request->session()->forget('dashboard_location');

            return [null, null];
        }

        return [(float) $location['lat'], (float) $location['lng']];
    }
}
