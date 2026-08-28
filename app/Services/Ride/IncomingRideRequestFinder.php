<?php

namespace App\Services\Ride;

use App\Models\FleetMember;
use App\Models\Review;
use App\Models\RideRequest;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Solicitudes que un conductor puede atender ahora mismo (dirigidas a él o
 * a toda su flota) — extraído de RideController::incomingRequestsForDriver()
 * (roadmap app móvil, Hito 5: nunca duplicar una regla de negocio entre
 * web y móvil). Extracción literal, misma lógica que ya cubre
 * tests/Feature/Ride/RideRequestFlowTest.php.
 */
class IncomingRideRequestFinder
{
    /**
     * @return Collection<int, RideRequest>
     */
    public function forDriver(User $driver): Collection
    {
        $userId = $driver->id;

        $incoming = RideRequest::query()
            ->where(function ($query) use ($userId) {
                $query->where('status', 'pending')
                    ->where(function ($query) use ($userId) {
                        $query->where('driver_user_id', $userId)
                            ->orWhere(function ($query) use ($userId) {
                                $query->whereNull('driver_user_id')
                                    ->whereIn('fleet_id', function ($sub) use ($userId) {
                                        $sub->select('fleet_id')
                                            ->from('fleet_members')
                                            ->where('driver_user_id', $userId)
                                            ->whereNull('left_at');
                                    });
                            });
                    });
            })
            ->orWhere(function ($query) use ($userId) {
                $query->where('status', 'negotiating')->where('negotiating_driver_user_id', $userId);
            })
            ->with(['client', 'originSector', 'destinationSector'])
            ->latest()
            ->get();

        $disabledFleetIds = FleetMember::query()
            ->where('driver_user_id', $userId)
            ->where('requests_disabled', true)
            ->pluck('fleet_id');

        $incoming = $incoming
            ->filter(fn (RideRequest $rideRequest) => $rideRequest->driver_user_id === $userId
                || $rideRequest->negotiating_driver_user_id === $userId
                || (! $disabledFleetIds->contains($rideRequest->fleet_id)
                    && ($driver->driverProfile?->isWithinRangeOf((float) $rideRequest->origin_lat, (float) $rideRequest->origin_lng) ?? true)))
            ->values();

        $ratings = Review::query()
            ->whereIn('reviewee_user_id', $incoming->pluck('client_user_id')->unique())
            ->selectRaw('reviewee_user_id, avg(rating) as avg_rating, count(*) as review_count')
            ->groupBy('reviewee_user_id')
            ->get()
            ->keyBy('reviewee_user_id');

        $incoming->each(function (RideRequest $rideRequest) use ($ratings) {
            $rating = $ratings->get($rideRequest->client_user_id);

            $rideRequest->client_name = $rideRequest->client->name;
            $rideRequest->client_rating = $rating ? round((float) $rating->avg_rating, 1) : 0;
            $rideRequest->client_review_count = $rating->review_count ?? 0;
            $rideRequest->client_member_code = $rideRequest->client->member_code;
        });

        return $incoming;
    }
}
