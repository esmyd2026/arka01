<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use App\Models\FleetMember;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\Sector;
use App\Models\User;
use App\Notifications\DemandAlertPushNotification;
use App\Services\Haversine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Centro de operaciones (pedido explícito del usuario): dónde se concentran
 * las solicitudes activas, cuántos clientes/conductores están conectados
 * ahora, y la demanda histórica por horario y por zona — para poder avisarle
 * a los conductores cercanos dónde conviene estar (ver notifyNearby()).
 */
class OperationsController extends Controller
{
    private const CONNECTED_WINDOW_MINUTES = 5;

    private const NEARBY_RADIUS_KM = 5;

    public function index(): Response
    {
        // Dónde se concentran las solicitudes ACTIVAS ahora mismo (pedido
        // explícito del usuario) — para el mapa.
        $pendingRequests = RideRequest::query()
            ->whereIn('status', ['pending', 'negotiating'])
            ->with(['client', 'originSector'])
            ->get();

        $activeDemand = $pendingRequests->map(fn (RideRequest $r) => [
            'id' => $r->id,
            'client_name' => $r->client->name,
            'lat' => (float) $r->origin_lat,
            'lng' => (float) $r->origin_lng,
            'sector' => $r->originSector?->name,
        ]);

        // Sectores con demanda AHORA MISMO (distinto de demandByZone, que es
        // histórico) — de acá sale a quién de verdad tiene sentido avisarle
        // a los conductores cercanos (notifyNearby() solo funciona con
        // solicitudes activas, no con historial).
        $activeSectors = $pendingRequests
            ->whereNotNull('origin_sector_id')
            ->groupBy('originSector.name')
            ->map(fn ($group, $sectorName) => [
                'sector_id' => $group->first()->origin_sector_id,
                'sector_name' => $sectorName,
                'total' => $group->count(),
            ])
            ->values();

        return Inertia::render('Admin/Operations', [
            'activeDemand' => $activeDemand,
            'activeSectors' => $activeSectors,
            'connected' => $this->connectedCounts(),
            'demandByHour' => $this->demandByHour(),
            'demandByZone' => $this->demandByZone(),
            'waitTime' => $this->waitTimeStats(),
        ]);
    }

    /**
     * Reutiliza la tabla `sessions` (SESSION_DRIVER=database) en vez de
     * inventar un mecanismo de presencia aparte — `last_activity` ya es
     * exactamente "la última vez que este usuario hizo algo".
     */
    private function connectedCounts(): array
    {
        $threshold = now()->subMinutes(self::CONNECTED_WINDOW_MINUTES)->timestamp;

        $connectedUserIds = DB::table('sessions')
            ->whereNotNull('user_id')
            ->where('last_activity', '>=', $threshold)
            ->distinct()
            ->pluck('user_id');

        $roles = User::query()
            ->whereIn('id', $connectedUserIds)
            ->selectRaw('role, count(*) as total')
            ->groupBy('role')
            ->pluck('total', 'role');

        return [
            'clients' => (int) ($roles['cliente'] ?? 0),
            'drivers' => (int) ($roles['conductor'] ?? 0),
            'window_minutes' => self::CONNECTED_WINDOW_MINUTES,
        ];
    }

    /**
     * @return array<int, int> 24 posiciones (0 a 23), cuántas solicitudes se
     *                         pidieron en cada hora del día, histórico completo.
     */
    private function demandByHour(): array
    {
        // En PHP, no con HOUR()/TIMESTAMPDIFF() de SQL: son funciones de
        // MySQL que no existen en SQLite (motor de los tests) — portable así
        // para los dos, y el volumen de esta tabla no amerita más.
        $counts = RideRequest::query()->get(['requested_at'])->countBy(fn (RideRequest $r) => $r->requested_at->hour);

        return collect(range(0, 23))->map(fn ($hour) => (int) ($counts[$hour] ?? 0))->all();
    }

    /**
     * @return array<int, array{sector: string, total: int}>
     */
    private function demandByZone(): array
    {
        $counts = RideRequest::query()
            ->selectRaw('origin_sector_id, count(*) as total')
            ->groupBy('origin_sector_id')
            ->pluck('total', 'origin_sector_id');

        $sectorNames = Sector::query()->whereIn('id', $counts->keys()->filter())->pluck('name', 'id');

        return $counts
            ->map(fn ($total, $sectorId) => [
                'sector' => $sectorId ? ($sectorNames[$sectorId] ?? 'Sector eliminado') : 'Sin sector',
                'total' => (int) $total,
            ])
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /**
     * Cuánto tarda un cliente en conseguir conductor (pedido explícito del
     * usuario: "el tiempo que tiene un cliente esperando"), y cancelaciones
     * de carreras ya aceptadas — sobre todo si el conductor ya iba en camino
     * ("que eso también cuente para medir").
     */
    private function waitTimeStats(): array
    {
        // En PHP, no con TIMESTAMPDIFF() (MySQL, no existe en SQLite) — mismo
        // criterio que demandByHour().
        $responseSeconds = RideRequest::query()
            ->where('status', 'accepted')
            ->whereNotNull('responded_at')
            ->get(['requested_at', 'responded_at'])
            ->map(fn (RideRequest $r) => $r->requested_at->diffInSeconds($r->responded_at));

        return [
            'avg_wait_minutes' => $responseSeconds->isNotEmpty() ? round($responseSeconds->avg() / 60, 1) : null,
            'expired_count' => RideRequest::query()->where('status', 'expired')->count(),
            'cancelled_while_en_route_count' => Ride::query()->where('status', 'cancelled')->whereNotNull('started_at')->count(),
            'cancelled_before_pickup_count' => Ride::query()->where('status', 'cancelled')->whereNull('started_at')->count(),
        ];
    }

    /**
     * Avisar a los conductores cercanos que hay demanda en una zona puntual
     * (pedido explícito del usuario), indicándole a cada uno si es de la
     * flota que la tiene pendiente o no. El radio de la zona sale del
     * centroide de las solicitudes pendientes de ese sector, no de una
     * coordenada propia del sector (Sector no guarda lat/lng, solo nombre).
     */
    public function notifyNearby(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'sector_id' => ['required', 'integer', 'exists:sectors,id'],
        ]);

        $pendingInSector = RideRequest::query()
            ->where('origin_sector_id', $validated['sector_id'])
            ->whereIn('status', ['pending', 'negotiating'])
            ->get();

        if ($pendingInSector->isEmpty()) {
            throw ValidationException::withMessages([
                'sector_id' => 'No hay solicitudes activas en ese sector ahora mismo.',
            ]);
        }

        $centroidLat = $pendingInSector->avg('origin_lat');
        $centroidLng = $pendingInSector->avg('origin_lng');
        $fleetIdsWithDemand = $pendingInSector->pluck('fleet_id')->unique();
        $sectorName = Sector::query()->find($validated['sector_id'])?->name ?? 'la zona';

        $nearbyDrivers = DriverProfile::query()
            ->where('is_available', true)
            ->whereNull('suspended_at')
            ->whereNotNull('current_lat')
            ->whereNotNull('current_lng')
            ->with('user')
            ->get()
            ->filter(fn (DriverProfile $profile) => Haversine::distanceKm(
                (float) $centroidLat,
                (float) $centroidLng,
                (float) $profile->current_lat,
                (float) $profile->current_lng,
            ) <= self::NEARBY_RADIUS_KM);

        $driverFleetIds = FleetMember::query()
            ->whereIn('driver_user_id', $nearbyDrivers->pluck('user_id'))
            ->whereNull('left_at')
            ->get()
            ->groupBy('driver_user_id')
            ->map(fn ($members) => $members->pluck('fleet_id'));

        foreach ($nearbyDrivers as $profile) {
            $isFromOwnFleet = ($driverFleetIds->get($profile->user_id) ?? collect())
                ->intersect($fleetIdsWithDemand)
                ->isNotEmpty();

            $profile->user->notify(new DemandAlertPushNotification($sectorName, $isFromOwnFleet));
        }

        return back()->with('status', "Se avisó a {$nearbyDrivers->count()} conductor(es) cerca de {$sectorName}.");
    }
}
