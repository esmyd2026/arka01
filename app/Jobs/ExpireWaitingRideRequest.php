<?php

namespace App\Jobs;

use App\Services\RideDispatchAdvancer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Lista de espera (pedido explícito del usuario: "puedo dejar la carrera
 * pendiente hasta que uno se desocupe") — se encola al crear una solicitud
 * `waiting`, con 15 minutos de retraso (RideRequestController::store()). Si
 * para cuando corre ya se activó (RideDispatchAdvancer::activateNextWaitingRequest())
 * o se canceló, no hace nada — mismo criterio que ExpireRideOffer.
 *
 * Necesita un worker de verdad corriendo (`php artisan queue:work`) — con
 * QUEUE_CONNECTION=sync el retraso de 15 minutos no existe, correría al toque.
 */
class ExpireWaitingRideRequest implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $rideRequestId) {}

    public function handle(): void
    {
        RideDispatchAdvancer::expireIfStillWaiting($this->rideRequestId);
    }
}
