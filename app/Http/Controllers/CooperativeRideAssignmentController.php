<?php

namespace App\Http\Controllers;

use App\Events\CooperativeRideUpdated;
use App\Events\RideRequestCancelled;
use App\Events\RideRequested;
use App\Jobs\ExpireRideOffer;
use App\Models\CooperativeDriverMembership;
use App\Models\RideRequest;
use App\Models\User;
use App\Notifications\CooperativeRideAssignedPushNotification;
use App\Notifications\RideRequestedPushNotification;
use App\Services\RideDispatchCandidates;
use App\Services\WhatsAppFreeformSender;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CooperativeRideAssignmentController extends Controller
{
    /** Asigna o reasigna una solicitud desde el panel operativo. */
    public function assign(Request $request, RideRequest $rideRequest): RedirectResponse
    {
        $cooperative = $request->user()->cooperative()->firstOrFail();
        abort_unless($rideRequest->cooperative_id === $cooperative->id, 403);

        $validated = $request->validate([
            'driver_user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $membership = CooperativeDriverMembership::query()
            ->where('cooperative_id', $cooperative->id)
            ->where('driver_user_id', $validated['driver_user_id'])
            ->where('status', 'accepted')
            ->whereNull('ended_at')
            ->with('driver.driverProfile')
            ->first();

        if (! $membership || (! $rideRequest->is_scheduled && ! $membership->driver->driverProfile?->is_available)) {
            throw ValidationException::withMessages([
                'driver_user_id' => 'Seleccione un conductor activo y disponible de la cooperativa.',
            ]);
        }

        $previousDriverId = $rideRequest->driver_user_id;
        $timeoutSeconds = max(15, min(300, (int) $cooperative->response_timeout_seconds));
        $remaining = $rideRequest->is_scheduled ? [] : RideDispatchCandidates::forCooperative(
            $cooperative,
            (float) $rideRequest->origin_lat,
            (float) $rideRequest->origin_lng,
            (int) $rideRequest->passenger_count,
            (bool) $rideRequest->needs_trunk,
        );
        $remaining = array_values(array_filter($remaining, fn (int $id) => $id !== (int) $validated['driver_user_id']));

        $assigned = DB::transaction(function () use ($rideRequest, $validated, $remaining, $timeoutSeconds) {
            $locked = RideRequest::query()->lockForUpdate()->findOrFail($rideRequest->id);

            if (! in_array($locked->status, ['pending', 'expired'], true)) {
                throw ValidationException::withMessages(['ride_request' => 'Esta solicitud ya no admite asignación.']);
            }

            // Una reserva futura no vence en segundos. El conductor la ve
            // como programada y puede confirmarla sin la presión del
            // despacho inmediato.
            $expiresAt = $locked->is_scheduled ? null : now()->addSeconds($timeoutSeconds);
            $locked->update([
                'status' => 'pending',
                'driver_user_id' => (int) $validated['driver_user_id'],
                'dispatch_pool' => 'cooperative',
                'offer_candidate_ids' => $remaining ?: null,
                'current_offer_expires_at' => $expiresAt,
                'cooperative_assignment_status' => 'awaiting_driver',
                'cooperative_candidate_ids' => $remaining ?: null,
                'cooperative_offer_expires_at' => $expiresAt,
                'responded_at' => null,
            ]);

            return $locked->fresh();
        });

        if ($previousDriverId && $previousDriverId !== $assigned->driver_user_id) {
            $notice = new RideRequest(['driver_user_id' => $previousDriverId]);
            $notice->id = $assigned->id;
            broadcast(new RideRequestCancelled($notice));
        }

        broadcast(new RideRequested($assigned));
        $driver = User::find($assigned->driver_user_id);
        $driver?->notify(new RideRequestedPushNotification($assigned));
        $assigned->client->notify(new CooperativeRideAssignedPushNotification($assigned));
        broadcast(new CooperativeRideUpdated($assigned, 'assigned'));
        if ($driver) {
            WhatsAppFreeformSender::sendNewRideAlert($driver, $assigned);
        }

        if (! $assigned->is_scheduled) {
            ExpireRideOffer::dispatch($assigned->id, $assigned->driver_user_id)
                ->delay($assigned->current_offer_expires_at);
        }

        return back()->with('status', 'Solicitud asignada. El conductor debe aceptarla antes de que venza la oferta.');
    }
}
