<?php

namespace App\Console\Commands;

use App\Events\RideRequestExpired;
use App\Models\RideRequest;
use App\Notifications\RideRequestExpiredPushNotification;
use App\Services\WhatsAppFreeformSender;
use Illuminate\Console\Command;

/**
 * Bug encontrado en una auditoría del flujo completo de despacho (pedido
 * explícito del usuario: "revisa todo el flujo de una carrera... las que
 * son solicitadas a un conductor en especifico"): una solicitud INMEDIATA
 * dirigida a un conductor puntual (no de una bolsa) nunca tenía vencimiento
 * — si ese conductor no respondía, quedaba 'pending' para siempre, sin
 * cascada (no hay a quién más ofrecérsela) y sin aviso al cliente.
 *
 * No se resuelve con el mismo mecanismo de la bolsa (App\Jobs\ExpireRideOffer,
 * un Job encolado con delay()) a propósito: bajo QUEUE_CONNECTION=sync (sin
 * un worker de verdad corriendo) ese delay se ignora y el Job corre AL
 * TOQUE — para la bolsa eso ya se sabía y se evitaba con Queue::fake() en
 * los tests que la ejercitan, pero una solicitud dirigida es un fixture
 * usado por decenas de tests sin relación con esto: expirarla en el mismo
 * request que la crea rompía todo lo demás. Un comando periódico (mismo
 * criterio que rides:expire-overdue-scheduled-requests) evita el problema
 * de raíz — nunca corre síncrono con la creación de la solicitud.
 */
class ExpireOverdueDirectedRideRequests extends Command
{
    protected $signature = 'rides:expire-overdue-directed-requests';

    protected $description = 'Expira solicitudes inmediatas dirigidas a un conductor puntual que no respondió a tiempo';

    public function handle(): int
    {
        $overdue = RideRequest::query()
            ->where('status', 'pending')
            ->where('is_scheduled', false)
            ->whereNull('dispatch_pool')
            ->whereNull('cooperative_id')
            ->whereNotNull('driver_user_id')
            ->whereNotNull('current_offer_expires_at')
            ->where('current_offer_expires_at', '<', now())
            ->with(['client', 'driver'])
            ->get();

        foreach ($overdue as $rideRequest) {
            $driver = $rideRequest->driver;

            $rideRequest->update(['status' => 'expired', 'responded_at' => now()]);

            broadcast(new RideRequestExpired($rideRequest));
            $rideRequest->client->notify(new RideRequestExpiredPushNotification($rideRequest));

            // Mismo criterio que RideDispatchAdvancer::advanceOrExpire()
            // para el candidato que pierde el turno en una bolsa: avisarle
            // también a él, no solo al cliente.
            if ($driver) {
                WhatsAppFreeformSender::sendOfferExpiredNotice($driver);
            }
        }

        if ($overdue->isNotEmpty()) {
            $this->info("{$overdue->count()} solicitud(es) dirigida(s) vencida(s) sin respuesta.");
        }

        return self::SUCCESS;
    }
}
