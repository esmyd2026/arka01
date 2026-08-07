<?php

namespace App\Jobs;

use App\Services\RideDispatchAdvancer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Despacho secuencial estilo Uber (pedido explícito del usuario): se encola
 * al ofrecerle la carrera a un candidato, con 30 segundos de retraso
 * (RideRequestController::store(), App\Services\RideDispatchAdvancer). Si
 * para cuando corre el candidato ya respondió (o alguien más ya hizo
 * avanzar la cascada), no hace nada — RideDispatchAdvancer se encarga de
 * ese chequeo.
 *
 * Necesita un worker de verdad corriendo (`php artisan queue:work`) — con
 * QUEUE_CONNECTION=sync (el de antes) el retraso de 30 segundos no existe,
 * el trabajo correría al toque.
 */
class ExpireRideOffer implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $rideRequestId,
        public readonly ?int $expectedCurrentDriverId,
    ) {}

    public function handle(): void
    {
        RideDispatchAdvancer::advanceOrExpire($this->rideRequestId, $this->expectedCurrentDriverId);
    }
}
