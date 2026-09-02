<?php

namespace App\Jobs;

use App\Models\RideRequest;
use App\Services\WhatsAppFreeformSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pedido explícito del usuario: "si no ha recibido ninguna repuesta en unos
 * 30 segundos y la solicitud sigue viva que indique que se esta buscando un
 * conductor" — se encola al crear la solicitud por WhatsApp
 * (WhatsAppRideBookingHandler::createRide()), con 30 segundos de retraso.
 * Si para cuando corre ya hay conductor asignado, se canceló o expiró, no
 * hace nada — es un aviso de paciencia, no una acción sobre la solicitud.
 */
class NotifyWhatsAppStillSearchingForDriver implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $rideRequestId) {}

    public function handle(): void
    {
        $rideRequest = RideRequest::query()->with('client')->find($this->rideRequestId);

        if (! $rideRequest || ! in_array($rideRequest->status, ['pending', 'negotiating', 'waiting'], true)) {
            return;
        }

        $client = $rideRequest->client;
        if (! $client?->phone || ! $client->hasActiveWhatsAppSession()) {
            return;
        }

        WhatsAppFreeformSender::sendText(
            $client->phone,
            "🔎 Seguimos buscando un conductor para su solicitud #{$rideRequest->id}. Le avisamos por acá apenas alguien acepte."
        );
    }
}
