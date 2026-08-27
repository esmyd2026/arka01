<?php

namespace App\Console\Commands;

use App\Events\RideRequestExpired;
use App\Models\RideRequest;
use App\Notifications\ScheduledRideRequestExpiredPushNotification;
use Illuminate\Console\Command;

/**
 * Bug reportado por el usuario: una solicitud PROGRAMADA que ningún
 * conductor aceptó se quedaba en status='pending' para siempre, aunque la
 * hora pedida ya hubiera pasado — sin aviso para el cliente. Distinto del
 * despacho secuencial de 30 segundos (RideDispatchAdvancer): acá no hay
 * cascada ni candidato puntual, es una solicitud programada que cualquier
 * conductor de la bolsa podía tomar y nadie tomó a tiempo. Sin gracia: si
 * ya pasó la hora pedida y nadie la aceptó, no tiene sentido seguir
 * ofreciendo ese horario — se expira apenas se cruza `scheduled_at`.
 *
 * Bug adicional encontrado en una auditoría del flujo completo (mismo
 * pedido del usuario): 'negotiating' también queda atrapada — un conductor
 * puede contraofertar una solicitud programada (RideRequestController::counter(),
 * que no distingue programada de inmediata) y si el cliente nunca responde
 * esa contraoferta antes de la hora pedida, se quedaba pendiente para
 * siempre igual que 'pending', solo que nadie lo cubría.
 */
class ExpireOverdueScheduledRideRequests extends Command
{
    protected $signature = 'rides:expire-overdue-scheduled-requests';

    protected $description = 'Expira solicitudes programadas que nadie aceptó y cuya hora ya pasó';

    public function handle(): int
    {
        $overdue = RideRequest::query()
            ->whereIn('status', ['pending', 'negotiating'])
            ->where('is_scheduled', true)
            ->where('scheduled_at', '<', now())
            ->with('client')
            ->get();

        foreach ($overdue as $rideRequest) {
            $rideRequest->update(['status' => 'expired', 'responded_at' => now()]);

            broadcast(new RideRequestExpired($rideRequest));
            $rideRequest->client->notify(new ScheduledRideRequestExpiredPushNotification($rideRequest));
        }

        if ($overdue->isNotEmpty()) {
            $this->info("{$overdue->count()} solicitud(es) programada(s) vencida(s) sin conductor.");
        }

        return self::SUCCESS;
    }
}
