<?php

namespace App\Console\Commands;

use App\Events\RideRequested;
use App\Models\ExpressRoute;
use App\Models\Fleet;
use App\Models\RidePriceOffer;
use App\Models\RideRequest;
use App\Services\Haversine;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Motor de recurrencia de Expresos (sección 4.2 y 9.5): todos los días,
 * genera la solicitud de carrera de cada Expreso activo que corresponda
 * según su horario — el precio ya viene pactado (offered_price), no hay
 * negociación de nuevo cada vez, pero el conductor sigue teniendo que
 * aceptarla (puede estar enfermo o no disponible ese día puntual).
 *
 * Programado en app/Console/Kernel.php (diario, de madrugada).
 */
class GenerateExpressRides extends Command
{
    protected $signature = 'express:generate-rides';

    protected $description = 'Genera la solicitud de carrera del día para cada Expreso activo que corresponda';

    public function handle(): int
    {
        $today = now();

        $routes = ExpressRoute::query()
            ->where('status', 'active')
            ->whereNotNull('assigned_driver_user_id')
            ->get()
            ->filter(fn (ExpressRoute $route) => $route->runsOn($today));

        $generated = 0;

        foreach ($routes as $route) {
            // Evita duplicar si el comando corre dos veces el mismo día.
            $alreadyGeneratedToday = $route->rideRequests()
                ->whereDate('requested_at', $today->toDateString())
                ->exists();

            if ($alreadyGeneratedToday) {
                continue;
            }

            $rideRequest = $this->generateFor(
                $route,
                $route->client_user_id,
                (float) $route->origin_lat,
                (float) $route->origin_lng,
                $route->origin_address,
                (float) $route->destination_lat,
                (float) $route->destination_lng,
                $route->destination_address,
                $today,
            );

            broadcast(new RideRequested($rideRequest));

            $generated++;

            // Pedido explícito del usuario: si el Expreso está compartido, cada
            // acompañante aceptado también necesita su propia carrera con el
            // mismo conductor — el precio de cada una es la parte proporcional
            // (ExpressRoute::pricePerPerson()), el precio pactado con el
            // conductor no cambia, solo se reparte entre más gente.
            foreach ($route->companions()->where('status', 'accepted')->get() as $companion) {
                $companionRideRequest = $this->generateFor(
                    $route,
                    $companion->passenger_user_id,
                    (float) ($companion->origin_lat ?? $route->origin_lat),
                    (float) ($companion->origin_lng ?? $route->origin_lng),
                    $companion->origin_address ?? $route->origin_address,
                    (float) ($companion->destination_lat ?? $route->destination_lat),
                    (float) ($companion->destination_lng ?? $route->destination_lng),
                    $companion->destination_address ?? $route->destination_address,
                    $today,
                );

                broadcast(new RideRequested($companionRideRequest));
            }
        }

        $this->info("Expresos: {$generated} solicitud(es) generada(s) para hoy.");

        return self::SUCCESS;
    }

    /**
     * Genera UNA carrera del día, para el dueño del Expreso o para uno de sus
     * acompañantes aceptados (pedido explícito del usuario: compartir un
     * Expreso reparte el costo entre más gente) — cada quien con su propio
     * origen/destino (si difiere) y su parte proporcional del precio.
     */
    private function generateFor(
        ExpressRoute $route,
        int $clientUserId,
        float $originLat,
        float $originLng,
        ?string $originAddress,
        float $destinationLat,
        float $destinationLng,
        ?string $destinationAddress,
        Carbon $today,
    ): RideRequest {
        $fleet = Fleet::query()
            ->where('owner_user_id', $clientUserId)
            ->orderBy('id')
            ->first()
            ?? Fleet::query()->create(['owner_user_id' => $clientUserId, 'name' => 'Mi flota']);

        $distanceKm = round(Haversine::distanceKm($originLat, $originLng, $destinationLat, $destinationLng), 2);

        // Compartido = el total pactado se reparte entre el dueño y sus
        // acompañantes aceptados; sin compartir, es el precio de siempre.
        $price = $route->share_enabled ? $route->pricePerPerson() : (float) $route->offered_price;

        return DB::transaction(function () use ($route, $fleet, $clientUserId, $originLat, $originLng, $originAddress, $destinationLat, $destinationLng, $destinationAddress, $distanceKm, $price, $today) {
            $rideRequest = RideRequest::query()->create([
                'fleet_id' => $fleet->id,
                'express_route_id' => $route->id,
                'client_user_id' => $clientUserId,
                'driver_user_id' => $route->assigned_driver_user_id,
                'origin_lat' => $originLat,
                'origin_lng' => $originLng,
                'origin_address' => $originAddress,
                'destination_lat' => $destinationLat,
                'destination_lng' => $destinationLng,
                'destination_address' => $destinationAddress,
                'distance_km' => $distanceKm,
                'status' => 'pending',
                'current_offered_price' => $price,
                'negotiation_round' => 0,
                'last_offer_made_by' => 'client',
                'requested_at' => $today->copy()->setTimeFromTimeString($route->departure_time),
            ]);

            RidePriceOffer::query()->create([
                'ride_request_id' => $rideRequest->id,
                'offered_by_user_id' => $clientUserId,
                'offered_amount' => $price,
            ]);

            return $rideRequest;
        });
    }
}
