<?php

namespace App\Http\Controllers;

use App\Models\AdBanner;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetInvitation;
use App\Models\FleetMember;
use App\Models\Review;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Services\Haversine;
use App\Services\PlanLimits;
use App\Services\WhatsAppConfig;
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
    public function __construct(private readonly PlanLimits $planLimits) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $userId = $user->id;

        $driverStats = null;
        $upcomingTrips = null;
        $inviteCode = null;
        $earningsSparkline = null;
        $driverFleetIds = null;
        $whatsappSession = null;

        if ($user->isDriver()) {
            // Flotas donde deshabilité las solicitudes del cliente dueño
            // (pedido explícito del usuario) — mismo criterio que RideController::index().
            $disabledFleetIds = FleetMember::query()
                ->where('driver_user_id', $userId)
                ->where('requests_disabled', true)
                ->pluck('fleet_id');

            $driverStats = [
                'active_clients' => FleetMember::query()->where('driver_user_id', $userId)->whereNull('left_at')->count(),
                'pending_requests' => $this->incomingRequestsQuery($userId)
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
                'earnings_this_month' => (float) Ride::query()
                    ->where('driver_user_id', $userId)
                    ->where('status', 'completed')
                    ->whereMonth('completed_at', now()->month)
                    ->whereYear('completed_at', now()->year)
                    ->sum('price'),
                'rating' => round((float) $user->reviewsReceived()->avg('rating'), 1),
                'review_count' => $user->reviewsReceived()->count(),
                'is_available' => (bool) $user->driverProfile?->is_available,
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
        $clientPlanName = null;

        if ($user->isClient()) {
            // Pedido explícito del usuario (diseño nuevo de Inicio): la
            // insignia "Cliente · Plan X" junto al saludo, mismo criterio que
            // ya usa la insignia "Conductor" del otro lado.
            $clientPlanName = $this->planLimits->forClient($user)['plan_name'];
            // Mismo criterio que FleetController::index()/RideRequestController:
            // la primera flota se crea sola al primer uso, para no pedir un paso
            // extra de "crear flota" — acá hace falta un id de flota real para
            // que el botón "Agregar" de "Conductores cerca" tenga adónde invitar.
            $fleet = Fleet::query()->where('owner_user_id', $userId)->orderBy('id')->first()
                ?? Fleet::query()->create(['owner_user_id' => $userId, 'name' => 'Mi flota']);

            $fleetDrivers = $this->fleetDriversFor($request, $fleet);
            $nearbyDrivers = $this->nearbyDriversFor($request, $userId, $fleetDrivers->pluck('user_id'));
            $targetFleetId = $fleet->id;
            $upcomingTrips = $this->upcomingTripsFor($userId, asDriver: false);
        }

        return Inertia::render('Dashboard', [
            'driverStats' => $driverStats,
            'fleetDrivers' => $fleetDrivers,
            'nearbyDrivers' => $nearbyDrivers,
            'targetFleetId' => $targetFleetId,
            'clientPlanName' => $clientPlanName,
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
        ]);
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
        $lat = $request->float('lat') ?: null;
        $lng = $request->float('lng') ?: null;

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
                    'name' => $member->driver->name,
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
     * "Conductores cerca" del inicio (consideración agregada al alcance): la
     * misma red de respaldo del directorio público (sección 3.4), pero acá
     * acotada a los realmente disponibles ahora mismo y sin volver a mostrar
     * a quien ya integra la flota del cliente. Ordena por cercanía si el
     * navegador ya compartió ubicación (`lat`/`lng` por query string, mismo
     * patrón que `DriverDirectoryController`), si no por mejor calificados.
     *
     * @return Collection<int, array>
     */
    private function nearbyDriversFor(Request $request, int $userId, Collection $excludeDriverIds): Collection
    {
        $lat = $request->float('lat') ?: null;
        $lng = $request->float('lng') ?: null;

        $profiles = DriverProfile::query()
            ->where('is_public', true)
            ->where('is_available', true)
            ->where('verification_status', '!=', 'rejected')
            ->where('user_id', '!=', $userId)
            ->whereNotIn('user_id', $excludeDriverIds)
            ->with('user')
            ->get();

        $ratings = Review::query()
            ->whereIn('reviewee_user_id', $profiles->pluck('user_id'))
            ->selectRaw('reviewee_user_id, avg(rating) as avg_rating, count(*) as review_count')
            ->groupBy('reviewee_user_id')
            ->get()
            ->keyBy('reviewee_user_id');

        $pendingInvitationDriverIds = Fleet::query()->where('owner_user_id', $userId)->pluck('id')
            ->pipe(fn ($fleetIds) => FleetInvitation::query()
                ->whereIn('fleet_id', $fleetIds)
                ->where('status', 'pending')
                ->pluck('driver_user_id'));

        $entries = $profiles->map(function (DriverProfile $profile) use ($ratings, $lat, $lng, $pendingInvitationDriverIds) {
            $rating = $ratings->get($profile->user_id);
            $distanceKm = ($lat !== null && $lng !== null && $profile->current_lat !== null)
                ? Haversine::distanceKm($lat, $lng, (float) $profile->current_lat, (float) $profile->current_lng)
                : null;

            return [
                'user_id' => $profile->user_id,
                'name' => $profile->user->name,
                'avatar_url' => $profile->user->avatar_url,
                'average_rating' => $rating ? round((float) $rating->avg_rating, 1) : null,
                'review_count' => $rating->review_count ?? 0,
                'distance_km' => $distanceKm,
                'already_invited' => $pendingInvitationDriverIds->contains($profile->user_id),
            ];
        });

        $entries = $lat !== null
            ? $entries->sortBy(fn ($e) => $e['distance_km'] ?? PHP_FLOAT_MAX)
            : $entries->sortByDesc(fn ($e) => $e['average_rating'] ?? 0);

        return $entries->take(8)->values();
    }

    private function incomingRequestsQuery(int $userId)
    {
        return RideRequest::query()
            ->where('status', 'pending')
            ->where(function ($query) use ($userId) {
                $query->where('driver_user_id', $userId)
                    ->orWhere(function ($query) use ($userId) {
                        $query->whereNull('driver_user_id')
                            ->whereIn('fleet_id', function ($sub) use ($userId) {
                                $sub->select('fleet_id')
                                    ->from('fleet_members')
                                    ->where('driver_user_id', $userId)
                                    ->whereNull('left_at');
                            });
                    });
            });
    }
}
