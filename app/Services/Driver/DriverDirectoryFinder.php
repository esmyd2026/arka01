<?php

namespace App\Services\Driver;

use App\Models\DriverProfile;
use App\Models\DriverTier;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\Review;
use App\Models\User;
use App\Services\Haversine;
use App\Services\PlanLimits;
use App\Services\Trust\TrustIndexCalculator;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Directorio de conductores públicos (sección 3.4): la red de respaldo
 * cuando nadie de la flota personal está disponible — extraído de
 * DriverDirectoryController (roadmap app móvil, "full backend": nunca
 * duplicar una regla de negocio entre web y móvil).
 *
 * A esta escala alcanza con traer todos los públicos a memoria y ordenar
 * ahí; si el volumen crece, cachear esto en Redis queda anotado aparte.
 */
class DriverDirectoryFinder
{
    private const PER_PAGE = 12;

    public function __construct(
        private readonly PlanLimits $planLimits,
        private readonly TrustIndexCalculator $trustIndex,
    ) {}

    /**
     * @return array{drivers: LengthAwarePaginator, targetFleetId: int}
     */
    public function browse(User $client, ?float $lat, ?float $lng, int $page): array
    {
        $page = max(1, $page);

        $driverProfiles = DriverProfile::query()
            ->where('is_public', true)
            ->where('verification_status', '!=', 'rejected')
            ->whereNull('suspended_at')
            ->whereNull('deactivated_at')
            ->with('user.cooperativeDriverMemberships.cooperative')
            ->get();

        $userIds = $driverProfiles->pluck('user_id');

        // Para saber si cada conductor ya está invitado/es miembro de la
        // flota "principal" del cliente y así el botón "Invitar" sepa qué
        // mostrar sin otra vuelta al servidor.
        $fleet = Fleet::query()
            ->where('owner_user_id', $client->id)
            ->orderBy('id')
            ->first();

        if (! $fleet) {
            $fleet = Fleet::query()->create([
                'owner_user_id' => $client->id,
                'name' => 'Mi flota',
            ]);
        }

        $activeDriverIds = $fleet->activeMembers()->pluck('driver_user_id');
        $pendingDriverIds = $fleet->invitations()->where('status', 'pending')->pluck('driver_user_id');
        $clientCounts = FleetMember::query()
            ->whereIn('driver_user_id', $userIds)
            ->whereNull('left_at')
            ->selectRaw('driver_user_id, count(*) as aggregate')
            ->groupBy('driver_user_id')
            ->pluck('aggregate', 'driver_user_id');

        $ratings = Review::query()
            ->whereIn('reviewee_user_id', $userIds)
            ->selectRaw('reviewee_user_id, avg(rating) as avg_rating, count(*) as review_count')
            ->groupBy('reviewee_user_id')
            ->get()
            ->keyBy('reviewee_user_id');

        $entries = $driverProfiles
            // Un cliente no se ve a sí mismo en el directorio aunque también sea conductor.
            ->reject(fn (DriverProfile $profile) => $profile->user_id === $client->id)
            ->map(function (DriverProfile $profile) use ($ratings, $lat, $lng, $activeDriverIds, $pendingDriverIds, $clientCounts) {
                $rating = $ratings->get($profile->user_id);
                $cooperative = $profile->user->cooperativeDriverMemberships
                    ->first(fn ($membership) => $membership->status === 'accepted' && $membership->ended_at === null)?->cooperative;

                $distanceKm = ($lat !== null && $lng !== null && $profile->current_lat !== null)
                    ? Haversine::distanceKm($lat, $lng, (float) $profile->current_lat, (float) $profile->current_lng)
                    : null;

                return [
                    'user_id' => $profile->user_id,
                    'public_id' => $profile->user->public_id,
                    'name' => $profile->user->full_name,
                    'avatar_url' => $profile->user->avatar_url,
                    'rate_per_km' => $profile->rate_per_km,
                    'accepts_cash' => $profile->accepts_cash,
                    'accepts_transfer' => $profile->accepts_transfer,
                    'is_available' => $profile->is_available && $profile->isReachable($profile->user->hasActiveWhatsAppSession()),
                    'average_rating' => $rating ? round((float) $rating->avg_rating, 1) : null,
                    'review_count' => $rating->review_count ?? 0,
                    'distance_km' => $distanceKm,
                    'is_verified' => $profile->verification_status === 'approved'
                        && $this->planLimits->forDriver($profile->user)['verified_badge'],
                    'vehicle_type' => $profile->vehicleTypeLabel(),
                    'public_category' => $profile->public_category,
                    'public_category_label' => $profile->visiblePublicCategoryLabel(),
                    'cooperative' => $cooperative ? [
                        'public_id' => $cooperative->public_id,
                        'name' => $cooperative->name,
                    ] : null,
                    'clients_count' => (int) ($clientCounts[$profile->user_id] ?? 0),
                    'tier' => DriverTier::forPoints($profile->total_points)->toBadge(),
                    'status' => match (true) {
                        $activeDriverIds->contains($profile->user_id) => 'member',
                        $pendingDriverIds->contains($profile->user_id) => 'pending',
                        default => 'not_invited',
                    },
                ];
            })
            // Además de pagar un plan que habilite el directorio, hay que
            // haber ganado con carreras completadas una medalla marcada
            // como "aparece en público" (hoy Oro y Diamante).
            ->filter(fn ($e) => $e['tier']['is_public_eligible']);

        // Medalla más alta primero, y dentro de la misma medalla, cercanía
        // si tenemos ubicación del cliente, si no mejor calificados primero.
        $entries = $entries->sort(function ($a, $b) use ($lat) {
            $byTier = $b['tier']['min_points'] <=> $a['tier']['min_points'];
            if ($byTier !== 0) {
                return $byTier;
            }

            return $lat !== null
                ? ($a['distance_km'] ?? PHP_FLOAT_MAX) <=> ($b['distance_km'] ?? PHP_FLOAT_MAX)
                : ($b['average_rating'] ?? 0) <=> ($a['average_rating'] ?? 0);
        })->values();

        $paginated = new LengthAwarePaginator(
            $entries->forPage($page, self::PER_PAGE)->values(),
            $entries->count(),
            self::PER_PAGE,
            $page,
        );

        // Solo la página visible recibe el cálculo personalizado para no
        // multiplicar consultas por todos los conductores del directorio.
        $paginated->setCollection($paginated->getCollection()->map(function (array $entry) use ($client) {
            $driver = User::query()->findOrFail($entry['user_id']);
            $entry['trust'] = $this->trustIndex->calculate($driver, $client);

            return $entry;
        }));

        return ['drivers' => $paginated, 'targetFleetId' => $fleet->id];
    }
}
