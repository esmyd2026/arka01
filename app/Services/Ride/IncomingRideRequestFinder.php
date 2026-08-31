<?php

namespace App\Services\Ride;

use App\Models\FleetMember;
use App\Models\Review;
use App\Models\RideRequest;
use App\Models\User;
use App\Services\PriceCalculator;
use App\Services\Trust\TrustIndexCalculator;
use Illuminate\Support\Collection;

/**
 * Solicitudes que un conductor puede atender ahora mismo (dirigidas a él o
 * a toda su flota) — extraído de RideController::incomingRequestsForDriver()
 * (roadmap app móvil, Hito 5: nunca duplicar una regla de negocio entre
 * web y móvil). Extracción literal, misma lógica que ya cubre
 * tests/Feature/Ride/RideRequestFlowTest.php.
 */
class IncomingRideRequestFinder
{
    public function __construct(private readonly TrustIndexCalculator $trustIndex) {}

    /**
     * @return Collection<int, RideRequest>
     */
    public function forDriver(User $driver): Collection
    {
        $userId = $driver->id;

        $incoming = RideRequest::query()
            ->where(function ($query) use ($userId) {
                $query->where('status', 'pending')
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
            })
            ->orWhere(function ($query) use ($userId) {
                $query->where('status', 'negotiating')->where('negotiating_driver_user_id', $userId);
            })
            // Bug real reportado por el usuario ("revisa si le llega al
            // conductor igual"): sin cargar 'stops' acá, el conductor
            // aceptaba una solicitud con paradas creyendo que era un viaje
            // directo — ni la pantalla ni el aviso por WhatsApp mostraban
            // las paradas ni su parte del precio (`stops_price`), recién
            // aparecían en rides.show DESPUÉS de aceptar, ya comprometido.
            ->with(['client', 'originSector', 'destinationSector', 'stops'])
            ->latest()
            ->get();

        $disabledFleetIds = FleetMember::query()
            ->where('driver_user_id', $userId)
            ->where('requests_disabled', true)
            ->pluck('fleet_id');

        $incoming = $incoming
            ->filter(fn (RideRequest $rideRequest) => $rideRequest->driver_user_id === $userId
                || $rideRequest->negotiating_driver_user_id === $userId
                || (! $disabledFleetIds->contains($rideRequest->fleet_id)
                    && ($driver->driverProfile?->isWithinRangeOf((float) $rideRequest->origin_lat, (float) $rideRequest->origin_lng) ?? true)))
            ->values();

        $ratings = Review::query()
            ->whereIn('reviewee_user_id', $incoming->pluck('client_user_id')->unique())
            ->selectRaw('reviewee_user_id, avg(rating) as avg_rating, count(*) as review_count')
            ->groupBy('reviewee_user_id')
            ->get()
            ->keyBy('reviewee_user_id');

        // Cargo por trayecto de recogida (pedido explícito del usuario:
        // "que se desglose... para que vea sus ganancias bien"): el margen
        // fijo de 0.8 km ya viene incluido en `current_offered_price` sin
        // mostrarse nunca por separado — acá se expone cuánto representa en
        // dinero, con la tarifa vigente de ESTE conductor, para que entienda
        // de dónde sale su ganancia antes de aceptar.
        $ratePerKm = (float) ($driver->driverProfile?->rate_per_km ?? 0);
        $routePaddingFare = round(PriceCalculator::DISTANCE_PADDING_KM * $ratePerKm, 2);

        $comparisonService = app(RideOfferComparison::class);

        $incoming->each(function (RideRequest $rideRequest) use ($ratings, $driver, $routePaddingFare, $comparisonService) {
            $rating = $ratings->get($rideRequest->client_user_id);

            $rideRequest->client_name = $rideRequest->client->name;
            $rideRequest->client_rating = $rating ? round((float) $rating->avg_rating, 1) : 0;
            $rideRequest->client_review_count = $rating->review_count ?? 0;
            $rideRequest->client_member_code = $rideRequest->client->member_code;
            $rideRequest->client_trust = $this->trustIndex->calculate($rideRequest->client, $driver);
            $rideRequest->route_padding_km = PriceCalculator::DISTANCE_PADDING_KM;
            $rideRequest->route_padding_fare = $routePaddingFare;
            // Bug real reportado por el usuario: `current_offered_price` es
            // SOLO el tramo final (última parada → destino, ver
            // RideRequestCreator::create()) — sin esto, el conductor veía un
            // precio menor al que en realidad le corresponde por todo el
            // recorrido con paradas.
            $rideRequest->total_offered_price = $rideRequest->clientTotalPrice();
            $rideRequest->driver_total_offered_price = $rideRequest->driverPayEstimate();
            $rideRequest->is_cooperative_request = $rideRequest->cooperative_id !== null;
            $rideRequest->cooperative_driver_rate_per_km = $rideRequest->cooperative?->driver_pay_rate_per_km;
            $rideRequest->offer_comparison = $comparisonService->forDriver($rideRequest, $driver);
        });

        return $incoming;
    }
}
