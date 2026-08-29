<?php

namespace App\Services\Fleet;

use App\Models\DriverProfile;
use App\Models\DriverTier;
use App\Models\Fleet;
use App\Models\Review;
use App\Models\Ride;
use App\Models\User;
use App\Services\Trust\TrustIndexCalculator;
use Illuminate\Support\Collection;

/**
 * Búsqueda de conductores para invitar a una flota — extraído de
 * FleetController::searchDrivers() (roadmap Hito 2/5: nunca duplicar una
 * regla entre web y móvil). Por código de socio, código de invitación,
 * nombre, apellido o usuario; nunca por teléfono (privacidad, pedido
 * explícito del usuario).
 */
class FleetDriverSearch
{
    public function __construct(private readonly TrustIndexCalculator $trustIndex) {}

    /**
     * @return Collection<int, array>
     */
    public function search(Fleet $fleet, string $term, User $viewer): Collection
    {
        $term = ltrim($term, '@');
        $memberCode = ctype_digit($term) ? (int) $term : null;

        $drivers = DriverProfile::query()
            ->with('user')
            ->where('user_id', '!=', $viewer->id)
            ->whereNull('deactivated_at')
            ->where(function ($query) use ($term, $memberCode) {
                $query->when($memberCode, fn ($query) => $query->whereHas('user', fn ($query) => $query->where('member_code', $memberCode)))
                    ->orWhere('invite_code', strtoupper($term))
                    ->orWhereHas('user', function ($query) use ($term) {
                        $query->where('name', 'like', '%'.$term.'%')
                            ->orWhere('last_name', 'like', '%'.$term.'%')
                            ->orWhere('username', 'like', '%'.$term.'%');
                    });
            })
            ->limit(10)
            ->get();

        $activeDriverIds = $fleet->activeMembers()->pluck('driver_user_id');
        $pendingDriverIds = $fleet->invitations()->where('status', 'pending')->pluck('driver_user_id');

        $driverIds = $drivers->pluck('user_id');

        $ratings = Review::query()
            ->whereIn('reviewee_user_id', $driverIds)
            ->selectRaw('reviewee_user_id, avg(rating) as avg_rating, count(*) as review_count')
            ->groupBy('reviewee_user_id')
            ->get()
            ->keyBy('reviewee_user_id');

        $rideCounts = Ride::query()
            ->whereIn('driver_user_id', $driverIds)
            ->where('status', 'completed')
            ->selectRaw('driver_user_id, count(*) as rides_count')
            ->groupBy('driver_user_id')
            ->pluck('rides_count', 'driver_user_id');

        return $drivers->map(function (DriverProfile $driver) use ($activeDriverIds, $pendingDriverIds, $ratings, $rideCounts, $viewer) {
            $rating = $ratings->get($driver->user_id);
            $averageRating = $rating ? round((float) $rating->avg_rating, 1) : null;
            $reviewCount = $rating->review_count ?? 0;

            return [
                'user_id' => $driver->user_id,
                'name' => $driver->user->full_name,
                'avatar_url' => $driver->user->avatar_url,
                'username' => $driver->user->username,
                'member_code' => $driver->user->member_code,
                'invite_code' => $driver->invite_code,
                'rate_per_km' => $driver->rate_per_km,
                'average_rating' => $averageRating,
                'review_count' => $reviewCount,
                'tier' => DriverTier::forPoints($driver->total_points)->toBadge(),
                'public_category' => $driver->public_category,
                'public_category_label' => $driver->visiblePublicCategoryLabel(),
                'rides_count' => $rideCounts->get($driver->user_id, 0),
                'active_clients_count' => $driver->activeClientCount(),
                'trust' => $this->trustIndex->calculate($driver->user, $viewer),
                'status' => match (true) {
                    $activeDriverIds->contains($driver->user_id) => 'member',
                    $pendingDriverIds->contains($driver->user_id) => 'pending',
                    default => 'not_invited',
                },
            ];
        })->values();
    }
}
