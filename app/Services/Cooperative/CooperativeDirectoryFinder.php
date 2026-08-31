<?php

namespace App\Services\Cooperative;

use App\Models\ClientCooperative;
use App\Models\Cooperative;
use App\Models\User;
use App\Services\CooperativeReputation;
use App\Services\PlanLimits;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Directorio de cooperativas y red de cooperativas del cliente — extraído de
 * CooperativeDirectoryController (roadmap app móvil, "full backend": nunca
 * duplicar una regla de negocio entre web y móvil).
 */
class CooperativeDirectoryFinder
{
    private const PER_PAGE = 12;

    public function __construct(
        private readonly PlanLimits $planLimits,
        private readonly CooperativeReputation $reputation,
    ) {}

    public function browse(?User $client, ?string $search, ?int $cityId, int $page): LengthAwarePaginator
    {
        $cooperatives = Cooperative::query()
            ->where('status', 'approved')
            ->whereNull('suspended_at')
            ->with('city')
            ->withCount(['activeDriverMemberships', 'clientLinks'])
            ->when(filled($search), function ($query) use ($search) {
                $term = trim($search);
                $query->where(fn ($query) => $query->where('name', 'like', "%{$term}%")
                    ->orWhere('legal_name', 'like', "%{$term}%")
                    ->orWhere('geographic_coverage', 'like', "%{$term}%"));
            })
            ->when($cityId !== null, fn ($query) => $query->where('city_id', $cityId))
            ->orderByDesc('declared_unit_count')
            ->paginate(self::PER_PAGE, page: max(1, $page));

        $attachedIds = $client?->isClient()
            ? ClientCooperative::query()->where('client_user_id', $client->id)->pluck('cooperative_id')
            : collect();

        $cooperatives->through(function (Cooperative $cooperative) use ($attachedIds) {
            $reputation = $this->reputation->summary($cooperative);

            return [
                'id' => $cooperative->id,
                'public_id' => $cooperative->public_id,
                'name' => $cooperative->name,
                'logo_url' => $cooperative->logo_url,
                'city' => $cooperative->city?->name,
                'province' => $cooperative->province,
                'coverage' => $cooperative->geographic_coverage,
                'operating_hours' => $cooperative->operating_hours,
                'driver_count' => $cooperative->active_driver_memberships_count,
                'unit_count' => $cooperative->declared_unit_count,
                'client_count' => $cooperative->client_links_count,
                'completed_rides' => $reputation['completed_rides'],
                'average_rating' => $reputation['average_rating'],
                'review_count' => $reputation['review_count'],
                'is_attached' => $attachedIds->contains($cooperative->id),
            ];
        });

        return $cooperatives;
    }

    /**
     * @return array{cooperative: Cooperative, reputation: array, drivers: Collection, fleetVisible: bool, reviews: Collection, isAttached: bool}
     */
    public function showProfile(Cooperative $cooperative, ?User $viewer): array
    {
        $canPreview = $cooperative->isApproved()
            || $viewer?->isAdmin()
            || $viewer?->cooperative?->is($cooperative);
        abort_unless($canPreview, 404);

        $cooperative->load('city');

        $canSeeFleet = $cooperative->show_fleet_publicly
            || $viewer?->isAdmin()
            || $viewer?->cooperative?->is($cooperative);

        return [
            'cooperative' => $cooperative,
            'reputation' => $this->reputation->summary($cooperative),
            'drivers' => $canSeeFleet ? $this->reputation->drivers($cooperative) : collect(),
            'fleetVisible' => $canSeeFleet,
            'reviews' => $this->reputation->recentReviews($cooperative),
            'isAttached' => $viewer?->isClient()
                ? ClientCooperative::query()->where('client_user_id', $viewer->id)->where('cooperative_id', $cooperative->id)->exists()
                : false,
        ];
    }

    public function attach(User $client, Cooperative $cooperative): void
    {
        abort_unless($client->isClient(), 403);
        abort_unless($cooperative->isApproved(), 404);

        $limits = $this->planLimits->forClient($client);
        $current = ClientCooperative::query()->where('client_user_id', $client->id)->count();

        if ($limits['max_cooperatives'] !== null && $current >= $limits['max_cooperatives']) {
            throw ValidationException::withMessages([
                'cooperative' => "Su plan permite guardar hasta {$limits['max_cooperatives']} cooperativa(s).",
            ]);
        }

        ClientCooperative::query()->firstOrCreate([
            'client_user_id' => $client->id,
            'cooperative_id' => $cooperative->id,
        ]);
    }

    public function detach(User $client, Cooperative $cooperative): void
    {
        abort_unless($client->isClient(), 403);

        ClientCooperative::query()
            ->where('client_user_id', $client->id)
            ->where('cooperative_id', $cooperative->id)
            ->delete();
    }
}
