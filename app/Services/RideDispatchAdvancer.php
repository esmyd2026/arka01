<?php

namespace App\Services;

use App\Events\RideRequestCancelled;
use App\Events\RideRequested;
use App\Events\RideRequestExpired;
use App\Jobs\ExpireRideOffer;
use App\Models\RideRequest;
use App\Models\User;
use App\Notifications\RideRequestedPushNotification;
use App\Notifications\RideRequestExpiredPushNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Despacho secuencial estilo Uber (pedido explícito del usuario): cuando el
 * candidato actual de una tanda no responde a tiempo (App\Jobs\ExpireRideOffer,
 * 30 segundos) o la rechaza a propósito (RideRequestController::reject()),
 * pasa al siguiente de `offer_candidate_ids` — o expira si ya no queda
 * ninguno. Un solo lugar para esta lógica, reutilizado por las dos vías.
 */
class RideDispatchAdvancer
{
    /**
     * @param  int|null  $expectedCurrentDriverId  si se pasa, no hace nada si
     *                                              la solicitud ya avanzó desde que se encoló este chequeo
     *                                              (protege contra una carrera entre el Job y una respuesta real).
     */
    public static function advanceOrExpire(int $rideRequestId, ?int $expectedCurrentDriverId = null): void
    {
        $outcome = DB::transaction(function () use ($rideRequestId, $expectedCurrentDriverId) {
            $rideRequest = RideRequest::query()->lockForUpdate()->findOrFail($rideRequestId);

            if ($rideRequest->status !== 'pending' || ! $rideRequest->isSequentialDispatch()) {
                return null;
            }

            if ($expectedCurrentDriverId !== null && $rideRequest->driver_user_id !== $expectedCurrentDriverId) {
                return null;
            }

            $previousDriverId = $rideRequest->driver_user_id;
            $remaining = $rideRequest->offer_candidate_ids ?? [];

            // Bug reportado por el usuario: un conductor de la bolsa podía
            // desconectarse (o pasar a suspendido, ocupado, fuera de rango)
            // MIENTRAS le tocaba el turno a otro — sin esto, igual le llegaba
            // la oferta cuando le tocaba a él, aunque ya no pudiera tomarla.
            // Se salta a los que ya no son elegibles hasta encontrar uno que
            // sí, o hasta vaciar la bolsa.
            $nextDriverId = null;

            while (! empty($remaining)) {
                $candidateId = array_shift($remaining);

                if (RideDispatchCandidates::isStillEligible(
                    $candidateId,
                    (float) $rideRequest->origin_lat,
                    (float) $rideRequest->origin_lng,
                    $rideRequest->passenger_count,
                    $rideRequest->needs_trunk,
                )) {
                    $nextDriverId = $candidateId;
                    break;
                }

                Log::info('Se saltó un candidato de la bolsa que ya no está elegible.', [
                    'ride_request_id' => $rideRequest->id,
                    'driver_user_id' => $candidateId,
                ]);
            }

            if ($nextDriverId === null) {
                $rideRequest->update(['status' => 'expired', 'responded_at' => now()]);

                return ['type' => 'expired', 'rideRequest' => $rideRequest, 'previousDriverId' => $previousDriverId];
            }

            $rideRequest->update([
                'driver_user_id' => $nextDriverId,
                'offer_candidate_ids' => $remaining,
                'current_offer_expires_at' => now()->addSeconds(30),
            ]);

            return ['type' => 'advanced', 'rideRequest' => $rideRequest->fresh(), 'previousDriverId' => $previousDriverId];
        });

        if ($outcome === null) {
            return;
        }

        /** @var RideRequest $rideRequest */
        $rideRequest = $outcome['rideRequest'];
        $previousDriverId = $outcome['previousDriverId'];

        // Avisa al que tenía el turno que ya se le acabó (mismo evento que ya
        // usa el cliente para cancelar — el frontend ya sabe sacarla de su
        // lista con esto, no hace falta uno nuevo).
        if ($previousDriverId) {
            $notice = new RideRequest(['driver_user_id' => $previousDriverId]);
            $notice->id = $rideRequest->id;
            broadcast(new RideRequestCancelled($notice));

            // Pedido explícito del usuario: que también se entere por
            // WhatsApp si no tiene la app abierta en ese momento.
            $previousDriver = User::find($previousDriverId);
            if ($previousDriver) {
                WhatsAppFreeformSender::sendOfferExpiredNotice($previousDriver);
            }
        }

        if ($outcome['type'] === 'expired') {
            Log::info('Nadie respondió a la solicitud dentro de los 30 segundos, expiró.', [
                'ride_request_id' => $rideRequest->id,
            ]);

            broadcast(new RideRequestExpired($rideRequest));
            $rideRequest->client->notify(new RideRequestExpiredPushNotification($rideRequest));

            return;
        }

        Log::info('Solicitud pasó al siguiente conductor de la bolsa (despacho secuencial).', [
            'ride_request_id' => $rideRequest->id,
            'previous_driver_user_id' => $previousDriverId,
            'next_driver_user_id' => $rideRequest->driver_user_id,
        ]);

        self::notifyCurrentCandidate($rideRequest);
    }

    /**
     * Avisa al candidato actual de la solicitud (WebSocket + push + WhatsApp)
     * y arma el vencimiento de 30 segundos — extraído de la rama "advanced"
     * de arriba para reutilizarlo también al activar una solicitud que
     * estaba `waiting` (ver activateNextWaitingRequest()): en los dos casos
     * es exactamente "ofrecerle la carrera al candidato actual", solo cambia
     * quién dispara el momento.
     */
    private static function notifyCurrentCandidate(RideRequest $rideRequest): void
    {
        broadcast(new RideRequested($rideRequest));

        $nextDriver = User::find($rideRequest->driver_user_id);
        $nextDriver?->notify(new RideRequestedPushNotification($rideRequest));
        // Mismo aviso por WhatsApp que al crear la solicitud (pedido
        // explícito del usuario), ahora para el siguiente candidato de la
        // bolsa — ver WhatsAppFreeformSender::sendNewRideAlert().
        if ($nextDriver) {
            WhatsAppFreeformSender::sendNewRideAlert($nextDriver, $rideRequest);
        }

        ExpireRideOffer::dispatch($rideRequest->id, $rideRequest->driver_user_id)
            ->delay($rideRequest->current_offer_expires_at);
    }

    /**
     * Lista de espera (pedido explícito del usuario: "puedo dejar la
     * carrera pendiente hasta que uno se desocupe y me atienda"). Se llama
     * cada vez que un conductor termina una carrera y queda libre
     * (RideController::complete()) — recorre las solicitudes `waiting` de
     * más antigua a más nueva (FIFO, confirmado con el usuario) y activa la
     * PRIMERA cuya bolsa (recalculada de cero, ya sin este conductor como
     * ocupado) no esté vacía. Si esa primera todavía no se puede atender
     * (ej. pide cajuela y este conductor no tiene), se prueba con la
     * siguiente — un conductor recién liberado alcanza para activar como
     * máximo una solicitud.
     */
    public static function activateNextWaitingRequest(): void
    {
        $waitingIds = RideRequest::query()
            ->where('status', 'waiting')
            ->orderBy('requested_at')
            ->pluck('id');

        foreach ($waitingIds as $rideRequestId) {
            $activated = DB::transaction(function () use ($rideRequestId) {
                $rideRequest = RideRequest::query()->lockForUpdate()->find($rideRequestId);

                // Ya se activó, se canceló o expiró mientras tanto (protege
                // contra que esto corra casi al mismo tiempo que
                // expireIfStillWaiting(), mismo criterio que advanceOrExpire()).
                if (! $rideRequest || $rideRequest->status !== 'waiting') {
                    return null;
                }

                $candidateIds = RideDispatchCandidates::forPool(
                    $rideRequest->fleet,
                    $rideRequest->client,
                    $rideRequest->dispatch_pool,
                    (float) $rideRequest->origin_lat,
                    (float) $rideRequest->origin_lng,
                    $rideRequest->passenger_count,
                    $rideRequest->needs_trunk,
                );

                if (empty($candidateIds)) {
                    return null;
                }

                $rideRequest->update([
                    'status' => 'pending',
                    'driver_user_id' => array_shift($candidateIds),
                    'offer_candidate_ids' => $candidateIds ?: null,
                    'current_offer_expires_at' => now()->addSeconds(30),
                ]);

                return $rideRequest->fresh();
            });

            if ($activated) {
                Log::info('Solicitud en espera activada: se liberó un conductor elegible.', [
                    'ride_request_id' => $activated->id,
                    'driver_user_id' => $activated->driver_user_id,
                ]);

                self::notifyCurrentCandidate($activated);

                return;
            }
        }
    }

    /**
     * A los 15 minutos de quedar `waiting` (ExpireWaitingRideRequest, encolado
     * desde RideRequestController::store()) — si para entonces ya se activó o
     * se canceló, no hace nada.
     */
    public static function expireIfStillWaiting(int $rideRequestId): void
    {
        $rideRequest = DB::transaction(function () use ($rideRequestId) {
            $rideRequest = RideRequest::query()->lockForUpdate()->find($rideRequestId);

            if (! $rideRequest || $rideRequest->status !== 'waiting') {
                return null;
            }

            $rideRequest->update(['status' => 'expired', 'responded_at' => now()]);

            return $rideRequest;
        });

        if (! $rideRequest) {
            return;
        }

        Log::info('Nadie se desocupó a tiempo para la solicitud en espera, expiró.', [
            'ride_request_id' => $rideRequest->id,
        ]);

        broadcast(new RideRequestExpired($rideRequest));
        $rideRequest->client->notify(new RideRequestExpiredPushNotification($rideRequest));
    }
}
