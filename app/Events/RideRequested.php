<?php

namespace App\Events;

use App\Models\RideRequest;
use App\Models\User;
use App\Services\Ride\RideOfferComparison;
use App\Services\Trust\TrustIndexCalculator;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Un cliente pidió una carrera (sección 3.5). Si la dirigió a un conductor
 * puntual, avisa solo a ese conductor (su canal personal, ya definido por
 * defecto en routes/channels.php); si la mandó a "toda la flota disponible",
 * avisa por el canal PERSONAL de cada miembro activo que esté dentro de su
 * propia zona de cobertura (pedido explícito del usuario: un conductor que
 * configuró un límite de distancia no se entera de una solicitud que le
 * queda lejos, aunque sea de su flota) — no se usa el canal compartido de la
 * flota para este evento puntual, porque ahí no hay forma de excluir a
 * alguien sin que el resto de los que sí escuchan ese canal (otros eventos,
 * como "ya la tomaron") se vean afectados.
 *
 * También al canal del CLIENTE (pedido explícito del usuario, lista de
 * espera): si su solicitud estaba `waiting` y se activa apenas alguien se
 * desocupa (RideDispatchAdvancer::activateNextWaitingRequest()), o si la
 * cascada avanza a otro candidato, su propia pantalla "Esperando respuesta"
 * se tiene que actualizar sola. Verificado que es seguro: el modal de
 * "carrera entrante" (IncomingRideRequestModal, que escucha este mismo
 * evento) solo se monta para conductores (AuthenticatedLayout.vue), un
 * cliente puro nunca tiene ese listener activo.
 */
class RideRequested implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public RideRequest $rideRequest) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel("App.Models.User.{$this->rideRequest->client_user_id}")];

        if ($this->rideRequest->isDirected()) {
            $channels[] = new PrivateChannel("App.Models.User.{$this->rideRequest->driver_user_id}");

            return $channels;
        }

        $memberIds = $this->rideRequest->fleet->activeMembers()->where('requests_disabled', false)->with('driver.driverProfile')->get()
            ->filter(fn ($member) => $member->driver->driverProfile?->isWithinRangeOf(
                (float) $this->rideRequest->origin_lat,
                (float) $this->rideRequest->origin_lng,
            ) ?? true)
            ->pluck('driver_user_id');

        return [...$channels, ...$memberIds->map(fn ($driverId) => new PrivateChannel("App.Models.User.{$driverId}"))->all()];
    }

    public function broadcastAs(): string
    {
        return 'ride-request.created';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $client = $this->rideRequest->client;
        $comparison = null;

        if ($this->rideRequest->driver_user_id) {
            $driver = User::find($this->rideRequest->driver_user_id);
            $comparison = $driver ? app(RideOfferComparison::class)->forDriver($this->rideRequest, $driver) : null;
        }

        return [
            'id' => $this->rideRequest->id,
            'client_name' => $client->name,
            // Pedido explícito del usuario ("que aparezca la imagen del
            // cliente"): mismo accesor que ya usa <UserAvatar> en el resto
            // de la app (ver User::getAvatarUrlAttribute()) — null si no
            // tiene foto, y el componente cae solo a las iniciales.
            'client_avatar_url' => $client->avatar_url,
            // Perfil de confianza del cliente (sección 3.6 y 8: "app segura",
            // el conductor tiene que poder ver quién le está pidiendo la
            // carrera antes de aceptar, no solo el nombre).
            'client_rating' => round((float) $client->reviewsReceived()->avg('rating'), 1),
            'client_review_count' => $client->reviewsReceived()->count(),
            'client_member_code' => $client->member_code,
            // Un evento puede llegar a varios conductores; usa el índice
            // público estable. La carga inicial sí personaliza vínculos en común.
            'client_trust' => app(TrustIndexCalculator::class)->calculate($client),
            'driver_user_id' => $this->rideRequest->driver_user_id,
            // Pedido explícito del usuario (lista de espera): para que la
            // pantalla del CLIENTE pueda mostrar quién es el candidato
            // actual sin otra consulta — mismo criterio que
            // negotiating_driver_name/declined_driver_name en otros eventos.
            'driver_name' => $this->rideRequest->driver?->name,
            'cooperative_id' => $this->rideRequest->cooperative_id,
            'cooperative_name' => $this->rideRequest->cooperative?->name,
            'origin_address' => $this->rideRequest->origin_address,
            'origin_sector' => $this->rideRequest->originSector ? ['name' => $this->rideRequest->originSector->name] : null,
            'destination_address' => $this->rideRequest->destination_address,
            'destination_sector' => $this->rideRequest->destinationSector ? ['name' => $this->rideRequest->destinationSector->name] : null,
            'distance_km' => $this->rideRequest->distance_km,
            // Forma de pago (pedido explícito del usuario): el conductor la
            // tiene que ver antes de aceptar, no solo en la pantalla de detalle.
            'payment_method' => $this->rideRequest->payment_method,
            'status' => $this->rideRequest->status,
            'current_offered_price' => $this->rideRequest->current_offered_price,
            // Bug real reportado por el usuario ("cuando le da pedir carrera
            // sigue sin aparecer los costos por cada parada... supongo que
            // al conductor tampoco le aparece"): confirmado — este evento en
            // vivo (IncomingRideRequestModal, la primera pantalla que ve el
            // conductor) nunca mandaba las paradas ni el total real,
            // `current_offered_price` es solo el tramo final. El respaldo
            // por polling (RideController::syncRequests(), mismo
            // IncomingRideRequestFinder que ya se corrigió) sí las traía —
            // por eso el bug aparecía "a veces sí, a veces no" según cuál de
            // los dos caminos llegara primero.
            'stops_price' => (float) ($this->rideRequest->stops_price ?? 0),
            'total_offered_price' => round(
                (float) $this->rideRequest->current_offered_price + (float) ($this->rideRequest->stops_price ?? 0),
                2
            ),
            'driver_total_offered_price' => $this->rideRequest->driverPayEstimate(),
            'is_cooperative_request' => $this->rideRequest->cooperative_id !== null,
            'cooperative_driver_rate_per_km' => $this->rideRequest->cooperative?->driver_pay_rate_per_km,
            'offer_comparison' => $comparison,
            'stops' => $this->rideRequest->stops->map(fn ($stop) => [
                'sequence' => $stop->sequence,
                'address' => $stop->address,
                'leg_distance_km' => $stop->leg_distance_km !== null ? (float) $stop->leg_distance_km : null,
                'leg_price' => (float) $stop->leg_price,
            ])->values()->all(),
            'is_scheduled' => $this->rideRequest->is_scheduled,
            'scheduled_at' => $this->rideRequest->scheduled_at,
            'round_trip' => $this->rideRequest->round_trip,
            // Observación libre del cliente (pedido explícito del usuario),
            // para que el conductor la vea antes de aceptar, no solo después.
            'notes' => $this->rideRequest->notes,
            // Despacho secuencial estilo Uber (pedido explícito del usuario):
            // el frontend usa esto para mostrar el contéo regresivo de 30 seg.
            // antes de que la solicitud pase al siguiente conductor de la bolsa.
            'current_offer_expires_at' => $this->rideRequest->current_offer_expires_at,
        ];
    }
}
