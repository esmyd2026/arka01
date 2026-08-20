<?php

namespace App\Jobs;

use App\Services\RideDispatchAdvancer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Activa el despacho por cercanía si el operador no asignó dentro del plazo manual. */
class FallbackCooperativeAssignment implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $rideRequestId) {}

    public function handle(): void
    {
        RideDispatchAdvancer::startCooperativeDispatch($this->rideRequestId);
    }
}
