<?php

namespace App\Services\Driver;

use App\Models\DriverProfile;
use App\Models\DriverTier;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\Review;
use App\Models\Ride;
use App\Models\User;
use App\Services\Haversine;
use App\Services\PlanLimits;
use App\Services\Trust\TrustIndexCalculator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Directorio de conductores públicos (sección 3.4): la red de respaldo
 * cuando nadie de la flota personal está disponible — extraído de
 * DriverDirectoryController (roadmap app móvil, "full backend": nunca
 * duplicar una regla de negocio entre web y móvil).
 *
 * A esta escala alcanza con traer todos los públicos a memoria y ordenar
 * ahí; si el volumen crece, cachear esto en Redis queda anotado aparte.
 */
class DriverDirectoryFinder
{
    private const PER_PAGE = 12;

    /**
     * Pedido explícito del usuario para el mapa de "conductores cerca de mí"
     * (ver nearby() abajo): arranca en 2 km, ajustable desde el front.
     */
    private const DEFAULT_RADIUS_KM = 2.0;

    private const MAX_RADIUS_KM = 25.0;

    public function __construct(
        private readonly PlanLimits $planLimits,
        private readonly TrustIndexCalculator $trustIndex,
    ) {}

    /**
     * @return array{drivers: LengthAwarePaginator, targetFleetId: int}
     */
    public function browse(User $client, ?float $lat, ?float $lng, int $page, ?int $sectorId = null): array
    {
        $page = max(1, $page);

        $driverProfiles = $this->baseDriverQuery()
            // Filtro por sector (pedido explícito del usuario: "el cliente...
            // pueda ver a los conductores de su sector") — solo se aplica
            // cuando el cliente elige uno; sin filtro, todos los públicos
            // siguen apareciendo como siempre (ver coverageSectors(), vacío
            // por defecto para un conductor que todavía no declaró zona).
            ->when($sectorId, fn ($query) => $query->whereHas('coverageSectors', fn ($q) => $q->where('sectors.id', $sectorId)))
            ->get()
            ->reject(fn (DriverProfile $profile) => $profile->user_id === $client->id);

        $fleet = $this->fleetFor($client);
        $lookups = $this->lookupsFor($driverProfiles->pluck('user_id'), $fleet);

        $entries = $driverProfiles
            ->map(fn (DriverProfile $profile) => $this->mapEntry($profile, $lat, $lng, $lookups))
            // Además de pagar un plan que habilite el directorio, hay que
            // haber ganado con carreras completadas una medalla marcada
            // como "aparece en público" (hoy Oro y Diamante).
            ->filter(fn ($e) => $e['tier']['is_public_eligible']);

        // Medalla más alta primero, y dentro de la misma medalla, cercanía
        // si tenemos ubicación del cliente, si no mejor calificados primero.
        $entries = $entries->sort(function ($a, $b) use ($lat) {
            $byTier = $b['tier']['min_points'] <=> $a['tier']['min_points'];
            if ($byTier !== 0) {
                return $byTier;
            }

            return $lat !== null
                ? ($a['distance_km'] ?? PHP_FLOAT_MAX) <=> ($b['distance_km'] ?? PHP_FLOAT_MAX)
                : ($b['average_rating'] ?? 0) <=> ($a['average_rating'] ?? 0);
        })->values();

        $paginated = new LengthAwarePaginator(
            $entries->forPage($page, self::PER_PAGE)->values(),
            $entries->count(),
            self::PER_PAGE,
            $page,
        );

        // Solo la página visible recibe el cálculo personalizado para no
        // multiplicar consultas por todos los conductores del directorio.
        $paginated->setCollection($paginated->getCollection()->map(fn (array $entry) => $this->withTrust($entry, $client)));

        return ['drivers' => $paginated, 'targetFleetId' => $fleet->id];
    }

    /**
     * Conductores públicos con ubicación en vivo dentro de un radio del punto
     * dado (pedido explícito del usuario: "un mapa... que me recomiende según
     * la zona en la que estoy por mi ubicación, o puedo cambiar en el mapa").
     * A diferencia de browse(), no pagina — un radio acota naturalmente
     * cuántos entran — y ordena siempre por cercanía, no por medalla: acá el
     * eje es "quién está cerca ahora mismo", no "quién es mejor". Muestra
     * disponibles e in-disponibles por igual (pedido explícito): el pasajero
     * decide con quién intentar, no se les oculta de entrada.
     *
     * `coverage_sectors` (si el conductor declaró zona de trabajo, ver
     * DriverCoverageSectorsUpdater) viaja en cada entrada como dato
     * informativo, no como filtro: un conductor que hoy está físicamente
     * cerca aparece igual aunque no haya declarado esta zona como suya — el
     * criterio real acá es la ubicación en vivo, no la declaración.
     *
     * @return array{drivers: Collection, targetFleetId: int, radiusKm: float}
     */
    public function nearby(User $client, float $lat, float $lng, ?float $radiusKm = null): array
    {
        $radiusKm = min(max($radiusKm ?? self::DEFAULT_RADIUS_KM, 0.5), self::MAX_RADIUS_KM);

        $driverProfiles = $this->baseDriverQuery()
            ->whereNotNull('current_lat')
            ->whereNotNull('current_lng')
            ->get()
            ->reject(fn (DriverProfile $profile) => $profile->user_id === $client->id)
            ->filter(fn (DriverProfile $profile) => Haversine::distanceKm(
                $lat,
                $lng,
                (float) $profile->current_lat,
                (float) $profile->current_lng
            ) <= $radiusKm);

        $fleet = $this->fleetFor($client);
        $lookups = $this->lookupsFor($driverProfiles->pluck('user_id'), $fleet);

        $entries = $driverProfiles
            ->map(fn (DriverProfile $profile) => $this->mapEntry($profile, $lat, $lng, $lookups))
            ->filter(fn ($e) => $e['tier']['is_public_eligible'])
            ->sortBy('distance_km')
            ->values()
            ->map(fn (array $entry) => $this->withTrust($entry, $client));

        return ['drivers' => $entries, 'targetFleetId' => $fleet->id, 'radiusKm' => $radiusKm];
    }

    private function baseDriverQuery()
    {
        return DriverProfile::query()
            ->where('is_public', true)
            ->where('verification_status', '!=', 'rejected')
            ->whereNull('suspended_at')
            ->whereNull('deactivated_at')
            ->with(['user.cooperativeDriverMemberships.cooperative', 'coverageSectors']);
    }

    /**
     * Flota "principal" del cliente, para que el botón "Invitar"/"Agregar a
     * mi flota" sepa de entrada qué mostrar sin otra vuelta al servidor.
     */
    private function fleetFor(User $client): Fleet
    {
        $fleet = Fleet::query()
            ->where('owner_user_id', $client->id)
            ->orderBy('id')
            ->first();

        return $fleet ?? Fleet::query()->create([
            'owner_user_id' => $client->id,
            'name' => 'Mi flota',
        ]);
    }

    /**
     * @return array{ratings: Collection, clientCounts: Collection, rideCounts: Collection, activeDriverIds: Collection, pendingDriverIds: Collection}
     */
    private function lookupsFor(Collection $userIds, Fleet $fleet): array
    {
        return [
            // Calculados UNA sola vez por flota (no por conductor, ver
            // mapEntry()) — el bug real que esto evita: recorrer la lista de
            // miembros/invitaciones de la flota adentro del map() de cada
            // conductor dispara una consulta nueva por cada uno.
            'activeDriverIds' => $fleet->activeMembers()->pluck('driver_user_id'),
            'pendingDriverIds' => $fleet->invitations()->where('status', 'pending')->pluck('driver_user_id'),
            'ratings' => Review::query()
                ->whereIn('reviewee_user_id', $userIds)
                ->selectRaw('reviewee_user_id, avg(rating) as avg_rating, count(*) as review_count')
                ->groupBy('reviewee_user_id')
                ->get()
                ->keyBy('reviewee_user_id'),
            'clientCounts' => FleetMember::query()
                ->whereIn('driver_user_id', $userIds)
                ->whereNull('left_at')
                ->selectRaw('driver_user_id, count(*) as aggregate')
                ->groupBy('driver_user_id')
                ->pluck('aggregate', 'driver_user_id'),
            // Carreras completadas (pedido explícito del usuario: "cant
            // carreras" en la tarjeta del mapa de conductores cercanos) —
            // mismo criterio que FleetRosterBuilder para el resto de la app.
            'rideCounts' => Ride::query()
                ->whereIn('driver_user_id', $userIds)
                ->where('status', 'completed')
                ->selectRaw('driver_user_id, count(*) as aggregate')
                ->groupBy('driver_user_id')
                ->pluck('aggregate', 'driver_user_id'),
        ];
    }

    /**
     * @param  array{ratings: Collection, clientCounts: Collection, rideCounts: Collection, activeDriverIds: Collection, pendingDriverIds: Collection}  $lookups
     */
    private function mapEntry(DriverProfile $profile, ?float $lat, ?float $lng, array $lookups): array
    {
        $rating = $lookups['ratings']->get($profile->user_id);
        $cooperative = $profile->user->cooperativeDriverMemberships
            ->first(fn ($membership) => $membership->status === 'accepted' && $membership->ended_at === null)?->cooperative;

        $distanceKm = ($lat !== null && $lng !== null && $profile->current_lat !== null)
            ? Haversine::distanceKm($lat, $lng, (float) $profile->current_lat, (float) $profile->current_lng)
            : null;

        return [
            'user_id' => $profile->user_id,
            'public_id' => $profile->user->public_id,
            'name' => $profile->user->full_name,
            'avatar_url' => $profile->user->avatar_url,
            'rate_per_km' => $profile->rate_per_km,
            'accepts_cash' => $profile->accepts_cash,
            'accepts_transfer' => $profile->accepts_transfer,
            'is_available' => $profile->is_available && $profile->isReachable($profile->user->hasActiveWhatsAppSession()),
            'average_rating' => $rating ? round((float) $rating->avg_rating, 1) : null,
            'review_count' => $rating->review_count ?? 0,
            'rides_count' => (int) ($lookups['rideCounts'][$profile->user_id] ?? 0),
            'distance_km' => $distanceKm,
            // Posición en vivo (pedido explícito del usuario: pintarlo como
            // marcador en el mapa de "conductores cerca de mí") — null si
            // nunca compartió ubicación.
            'lat' => $profile->current_lat !== null ? (float) $profile->current_lat : null,
            'lng' => $profile->current_lng !== null ? (float) $profile->current_lng : null,
            'is_verified' => $profile->verification_status === 'approved'
                && $this->planLimits->forDriver($profile->user)['verified_badge'],
            'vehicle_type' => $profile->vehicleTypeLabel(),
            'public_category' => $profile->public_category,
            'public_category_label' => $profile->visiblePublicCategoryLabel(),
            'cooperative' => $cooperative ? [
                'public_id' => $cooperative->public_id,
                'name' => $cooperative->name,
            ] : null,
            // Zona de cobertura (pedido explícito del usuario): para que el
            // cliente vea, aunque no haya filtrado ni buscado por sector, en
            // qué zonas dice trabajar este conductor.
            'coverage_sectors' => $profile->coverageSectors->map(fn ($sector) => ['id' => $sector->id, 'name' => $sector->name])->values(),
            'clients_count' => (int) ($lookups['clientCounts'][$profile->user_id] ?? 0),
            'tier' => DriverTier::forPoints($profile->total_points)->toBadge(),
            'status' => match (true) {
                $lookups['activeDriverIds']->contains($profile->user_id) => 'member',
                $lookups['pendingDriverIds']->contains($profile->user_id) => 'pending',
                default => 'not_invited',
            },
        ];
    }

    private function withTrust(array $entry, User $client): array
    {
        $driver = User::query()->findOrFail($entry['user_id']);
        $entry['trust'] = $this->trustIndex->calculate($driver, $client);

        return $entry;
    }
}
