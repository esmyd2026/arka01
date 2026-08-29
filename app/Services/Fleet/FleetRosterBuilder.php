<?php

namespace App\Services\Fleet;

use App\Models\DriverProfile;
use App\Models\DriverTier;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\Review;
use App\Models\Ride;
use Illuminate\Support\Collection;

/**
 * Roster completo de una flota (conductores activos, invitaciones pendientes,
 * y las mismas estadísticas por conductor que también calcula
 * FleetController::searchDrivers() — calificación, carreras completadas,
 * categoría, clientes activos). Extraído de FleetController::fleetDetails()
 * (roadmap Hito 2/5: nunca duplicar una regla entre web y móvil) para que
 * Api\V1\FleetController use exactamente el mismo cálculo.
 */
class FleetRosterBuilder
{
    /**
     * @return array{fleet: Fleet, memberStats: Collection}
     */
    public function build(Fleet $fleet): array
    {
        $fleet->load([
            'activeMembers.driver.driverProfile',
            'invitations' => fn ($query) => $query->where('status', 'pending')->with(['driver', 'inviter']),
        ]);

        $driverIds = $fleet->activeMembers->pluck('driver_user_id');

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

        $clientCounts = FleetMember::query()
            ->whereIn('driver_user_id', $driverIds)
            ->whereNull('left_at')
            ->selectRaw('driver_user_id, count(*) as clients_count')
            ->groupBy('driver_user_id')
            ->pluck('clients_count', 'driver_user_id');

        $profiles = DriverProfile::query()->whereIn('user_id', $driverIds)->get()->keyBy('user_id');

        $memberStats = $driverIds->mapWithKeys(function ($driverId) use ($ratings, $rideCounts, $clientCounts, $profiles) {
            $rating = $ratings->get($driverId);
            $averageRating = $rating ? round((float) $rating->avg_rating, 1) : null;
            $reviewCount = $rating->review_count ?? 0;
            $profile = $profiles->get($driverId);

            return [$driverId => [
                'average_rating' => $averageRating,
                'review_count' => $reviewCount,
                'rides_count' => $rideCounts->get($driverId, 0),
                'tier' => DriverTier::forPoints($profile?->total_points ?? 0)->toBadge(),
                'public_category' => $profile?->public_category,
                'public_category_label' => $profile?->visiblePublicCategoryLabel(),
                'active_clients_count' => $clientCounts->get($driverId, 0),
            ]];
        });

        return ['fleet' => $fleet, 'memberStats' => $memberStats];
    }
}
