<?php

namespace App\Services;

use App\Models\Cooperative;
use App\Models\Review;
use App\Models\Ride;
use Illuminate\Support\Collection;

class CooperativeReputation
{
    private function rides(Cooperative $cooperative)
    {
        return Ride::query()->whereHas('rideRequest', fn ($query) => $query->where('cooperative_id', $cooperative->id));
    }

    /** Solo opiniones que el cliente dejó al conductor en una carrera de esta cooperativa. */
    private function reviews(Cooperative $cooperative)
    {
        return Review::query()->whereHas('ride', function ($query) use ($cooperative) {
            $query->whereColumn('rides.driver_user_id', 'reviews.reviewee_user_id')
                ->whereHas('rideRequest', fn ($requestQuery) => $requestQuery->where('cooperative_id', $cooperative->id));
        });
    }

    public function summary(Cooperative $cooperative): array
    {
        $reviews = $this->reviews($cooperative);
        $rides = $this->rides($cooperative);

        return [
            // Clientes que eligieron y mantienen vinculada esta cooperativa
            // en su flota. No es un estimado ni la suma de pasajeros únicos.
            'client_count' => $cooperative->clientLinks()->count(),
            // Pedido explícito del usuario ("que salga solo las cantidades"):
            // la cantidad tiene que seguir viéndose aunque la flota esté
            // oculta (Cooperative/Show.vue) — separado de drivers() de abajo,
            // que sí puede no llamarse cuando show_fleet_publicly es false.
            'driver_count' => $cooperative->activeDriverMemberships()->count(),
            'average_rating' => round((float) (clone $reviews)->avg('rating'), 1),
            'review_count' => (clone $reviews)->count(),
            'completed_rides' => (clone $rides)->where('status', 'completed')->count(),
            'cancelled_rides' => (clone $rides)->where('status', 'cancelled')->count(),
        ];
    }

    public function drivers(Cooperative $cooperative): Collection
    {
        $memberships = $cooperative->activeDriverMemberships()->with('driver.driverProfile')->get();
        $driverIds = $memberships->pluck('driver_user_id');

        $rideStats = $this->rides($cooperative)
            ->whereIn('driver_user_id', $driverIds)
            ->selectRaw("driver_user_id, sum(case when status = 'completed' then 1 else 0 end) as completed_rides, sum(case when status = 'cancelled' then 1 else 0 end) as cancelled_rides")
            ->groupBy('driver_user_id')->get()->keyBy('driver_user_id');
        $reviewStats = $this->reviews($cooperative)
            ->whereIn('reviewee_user_id', $driverIds)
            ->selectRaw('reviewee_user_id, avg(rating) as average_rating, count(*) as review_count')
            ->groupBy('reviewee_user_id')->get()->keyBy('reviewee_user_id');

        return $memberships->map(function ($membership) use ($rideStats, $reviewStats) {
            $driver = $membership->driver;
            $rides = $rideStats->get($driver->id);
            $reviews = $reviewStats->get($driver->id);

            return [
                'id' => $driver->id,
                'public_id' => $driver->public_id,
                'name' => $driver->name,
                'avatar_url' => $driver->avatar_url,
                'vehicle' => trim(implode(' ', array_filter([$driver->driverProfile?->vehicle_make, $driver->driverProfile?->vehicle_model]))),
                'completed_rides' => (int) ($rides?->completed_rides ?? 0),
                'cancelled_rides' => (int) ($rides?->cancelled_rides ?? 0),
                'average_rating' => round((float) ($reviews?->average_rating ?? 0), 1),
                'review_count' => (int) ($reviews?->review_count ?? 0),
            ];
        })->values();
    }

    public function recentReviews(Cooperative $cooperative, int $limit = 20): Collection
    {
        return $this->reviews($cooperative)
            ->whereNotNull('comment')->where('comment', '!=', '')
            ->with(['reviewer:id,name', 'reviewee:id,name', 'ride:id,origin_address,destination_address'])
            ->latest()->limit($limit)->get()->map(fn (Review $review) => [
                'id' => $review->id,
                'rating' => $review->rating,
                'comment' => $review->comment,
                'client' => $review->reviewer?->name ?? 'Cliente',
                'driver' => $review->reviewee?->name ?? 'Conductor',
                'route' => trim(($review->ride?->origin_address ?? '').' → '.($review->ride?->destination_address ?? '')),
                'date' => $review->created_at?->toIso8601String(),
            ]);
    }
}
