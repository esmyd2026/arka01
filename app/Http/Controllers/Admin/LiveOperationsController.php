<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Services\Haversine;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pedido explícito del usuario: "ver las transaciones que se estan
 * ejecutando ahorita... si una persona esta esperando un conductor, si
 * esta en curso una carrera... con el detalle: cliente esperando conductor
 * de tal lado a tal lado por tanto, y que salga las unidades cercanas".
 * Distinto de Admin\OperationsController (demanda histórica agregada, sin
 * detalle por transacción) — acá cada solicitud/carrera activa es una
 * tarjeta con su propio detalle, más el mapa. Sin Echo/canal admin (no
 * existe uno para esto todavía, ver routes/channels.php) — se refresca por
 * polling, mismo criterio que Cooperative/Dashboard.vue.
 */
class LiveOperationsController extends Controller
{
    /**
     * Radio para "unidades cercanas" en el detalle de cada solicitud en
     * espera — mismo valor que ya usa OperationsController::notifyNearby()
     * para "cerca" en el resto del panel admin.
     */
    private const NEARBY_RADIUS_KM = 5;

    private const NEARBY_LIMIT = 5;

    public function index(): Response
    {
        $requests = RideRequest::query()
            ->whereIn('status', ['pending', 'negotiating', 'waiting'])
            // Una programada para más tarde no es "está pasando ahora" — a
            // menos que su hora ya haya llegado (mismo criterio que el resto
            // de la app para tratar una programada vencida como inmediata).
            ->where(fn ($query) => $query->where('is_scheduled', false)->orWhere('scheduled_at', '<=', now()))
            ->with(['client', 'driver', 'fleet.owner'])
            ->latest('requested_at')
            ->get();

        $rides = Ride::query()
            ->where('status', 'in_progress')
            ->with(['client', 'driver.driverProfile'])
            ->latest('started_at')
            ->get();

        $availableDrivers = $this->currentlyAvailableDrivers();

        return Inertia::render('Admin/LiveOperations', [
            'waitingRequests' => $requests->map(fn (RideRequest $request) => $this->mapWaitingRequest($request, $availableDrivers))->values(),
            'activeRides' => $rides->map(fn (Ride $ride) => $this->mapActiveRide($ride))->values(),
            'stats' => [
                'waiting' => $requests->count(),
                'in_progress' => $rides->count(),
                'available_drivers' => $availableDrivers->count(),
            ],
        ]);
    }

    /**
     * Conductores disponibles ahora mismo en toda la plataforma, sin acotar
     * a una flota o cooperativa puntual (a diferencia de
     * RideDispatchCandidates, pensado para elegir A QUIÉN ofrecerle una
     * carrera puntual) — acá el objetivo es solo mostrarle al admin "esto es
     * lo que hay cerca", así que alcanza con disponible + ubicado + no
     * ocupado, sin el resto de las reglas de elegibilidad de despacho.
     */
    private function currentlyAvailableDrivers(): Collection
    {
        $busyDriverIds = Ride::query()->where('status', 'in_progress')->pluck('driver_user_id');

        return DriverProfile::query()
            ->where('is_available', true)
            ->whereNull('suspended_at')
            ->whereNull('deactivated_at')
            ->whereNotNull('current_lat')
            ->whereNotNull('current_lng')
            ->whereNotIn('user_id', $busyDriverIds)
            ->with('user')
            ->get()
            ->filter(fn (DriverProfile $profile) => ! $profile->isStale())
            ->values();
    }

    /**
     * @return array<int, array{user_id: int, name: string, avatar_url: ?string, distance_km: float, eta_minutes: int}>
     */
    private function nearbyDrivers(Collection $availableDrivers, float $lat, float $lng): array
    {
        return $availableDrivers
            ->map(function (DriverProfile $profile) use ($lat, $lng) {
                $distance = Haversine::distanceKm($lat, $lng, (float) $profile->current_lat, (float) $profile->current_lng);

                return [
                    'user_id' => $profile->user_id,
                    'name' => $profile->user->name,
                    'avatar_url' => $profile->user->avatar_url,
                    // Pedido explícito del usuario: "la idea es ver y
                    // muestra en el mapa tambien" — con lat/lng, el
                    // frontend también las pinta como marcador, no solo en
                    // la lista de la tarjeta.
                    'lat' => (float) $profile->current_lat,
                    'lng' => (float) $profile->current_lng,
                    'distance_km' => round($distance, 1),
                    'eta_minutes' => max(1, (int) ceil($distance / 0.45)),
                ];
            })
            ->filter(fn (array $driver) => $driver['distance_km'] <= self::NEARBY_RADIUS_KM)
            ->sortBy('distance_km')
            ->take(self::NEARBY_LIMIT)
            ->values()
            ->all();
    }

    /**
     * "Cliente esperando conductor de tal lado a tal lado por tanto"
     * (pedido explícito del usuario, casi textual) — el detalle completo de
     * una solicitud que todavía no se convirtió en carrera: si ya se le
     * ofreció a un conductor puntual (esperando que la acepte) o si el
     * motor todavía está buscando a quién ofrecérsela, más las unidades
     * cercanas para que el admin entienda si hay o no con qué atenderla.
     */
    private function mapWaitingRequest(RideRequest $request, Collection $availableDrivers): array
    {
        return [
            'id' => $request->id,
            'client' => [
                'id' => $request->client->id,
                'public_id' => $request->client->public_id,
                'name' => $request->client->name,
                'avatar_url' => $request->client->avatar_url,
            ],
            // Awaiting_driver: ya se le ofreció a alguien puntual y se
            // espera que la acepte o la rechace. Searching: el motor todavía
            // no encontró (o no terminó de recorrer) a quién ofrecérsela.
            'phase' => $request->driver_user_id ? 'awaiting_driver' : 'searching',
            'assigned_driver' => $request->driver ? [
                'id' => $request->driver->id,
                'name' => $request->driver->name,
                'avatar_url' => $request->driver->avatar_url,
            ] : null,
            'offer_expires_at' => $request->current_offer_expires_at?->toIso8601String(),
            'origin_address' => $request->origin_address,
            'origin_lat' => (float) $request->origin_lat,
            'origin_lng' => (float) $request->origin_lng,
            'destination_address' => $request->destination_address,
            'destination_lat' => $request->destination_lat !== null ? (float) $request->destination_lat : null,
            'destination_lng' => $request->destination_lng !== null ? (float) $request->destination_lng : null,
            'distance_km' => $request->distance_km !== null ? (float) $request->distance_km : null,
            'price' => $request->current_offered_price !== null
                ? round((float) $request->current_offered_price + (float) ($request->stops_price ?? 0), 2)
                : null,
            'is_scheduled' => (bool) $request->is_scheduled,
            'scheduled_at' => $request->scheduled_at?->toIso8601String(),
            'requested_at' => $request->requested_at?->toIso8601String(),
            'fleet_owner_name' => $request->fleet?->owner?->name,
            'nearby_drivers' => $this->nearbyDrivers($availableDrivers, (float) $request->origin_lat, (float) $request->origin_lng),
        ];
    }

    /**
     * "Esta en curso una carrera" (pedido explícito del usuario) — el sub-
     * estado sale de qué timestamps ya tiene la carrera (mismo orden que
     * RideController: heading → arrived → picked_up), no de una columna
     * `status` más granular que no existe (todas quedan en 'in_progress'
     * hasta completarse, ver Ride::isScheduledAndNotStarted()).
     */
    private function mapActiveRide(Ride $ride): array
    {
        $phase = match (true) {
            $ride->picked_up_at !== null => 'en_route_to_destination',
            $ride->arrived_at !== null => 'arrived_waiting_pickup',
            $ride->heading_to_passenger_at !== null => 'heading_to_passenger',
            default => 'accepted',
        };

        $driverProfile = $ride->driver->driverProfile ?? null;

        return [
            'id' => $ride->id,
            'client' => [
                'id' => $ride->client->id,
                'public_id' => $ride->client->public_id,
                'name' => $ride->client->name,
                'avatar_url' => $ride->client->avatar_url,
            ],
            'driver' => [
                'id' => $ride->driver->id,
                'public_id' => $ride->driver->public_id,
                'name' => $ride->driver->name,
                'avatar_url' => $ride->driver->avatar_url,
                'lat' => $driverProfile?->current_lat !== null ? (float) $driverProfile->current_lat : null,
                'lng' => $driverProfile?->current_lng !== null ? (float) $driverProfile->current_lng : null,
            ],
            'phase' => $phase,
            'origin_address' => $ride->origin_address,
            'origin_lat' => (float) $ride->origin_lat,
            'origin_lng' => (float) $ride->origin_lng,
            'destination_address' => $ride->destination_address,
            'destination_lat' => (float) $ride->destination_lat,
            'destination_lng' => (float) $ride->destination_lng,
            'distance_km' => $ride->distance_km !== null ? (float) $ride->distance_km : null,
            'price' => $ride->price !== null ? (float) $ride->price : null,
            'started_at' => $ride->started_at?->toIso8601String(),
        ];
    }
}
