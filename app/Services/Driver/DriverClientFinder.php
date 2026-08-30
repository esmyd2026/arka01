<?php

namespace App\Services\Driver;

use App\Models\Fleet;
use App\Models\FleetInvitation;
use App\Models\FleetMember;
use App\Models\Review;
use App\Models\Ride;
use App\Models\TrustCircleConnection;
use App\Models\User;
use App\Services\DriverCategory;
use App\Services\PlanLimits;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "Mis clientes de confianza" del lado conductor: invitaciones pendientes,
 * flotas a las que ya pertenece, y buscar clientes para mandarles una
 * solicitud — extraído de DriverInvitationController (roadmap app móvil,
 * "full backend": nunca duplicar una regla de negocio entre web y móvil).
 */
class DriverClientFinder
{
    public function __construct(private readonly PlanLimits $planLimits) {}

    /**
     * @return array{pendingInvitations: Collection, activeMemberships: LengthAwarePaginator, activeMembershipStats: array, maxClients: int|null, planCode: string, planName: string, inviteCode: ?string}
     */
    public function myClients(User $driver, ?string $filter, ?string $sort, int $page): array
    {
        $userId = $driver->id;

        $pendingInvitations = FleetInvitation::query()
            ->where('driver_user_id', $userId)
            ->where('status', 'pending')
            // 'inviter' (pedido explícito del usuario, "Recomendar mi
            // flota"): permite mostrar "Recomendado por X" cuando quien
            // invitó no es el dueño de la flota.
            ->with(['fleet.owner', 'inviter'])
            ->latest()
            ->get();

        $mutualClients = $this->mutualClientsForInvitations($driver, $pendingInvitations);

        $pendingInvitations = $pendingInvitations
            ->map(fn (FleetInvitation $invitation) => array_merge(
                $invitation->toArray(),
                $this->clientReviewStats($invitation->fleet->owner_user_id),
                $mutualClients[$invitation->fleet->owner_user_id] ?? [
                    'mutual_clients_count' => 0,
                    'mutual_clients' => [],
                ],
            ));

        $allMemberships = FleetMember::query()
            ->where('driver_user_id', $userId)
            ->whereNull('left_at')
            ->with(['fleet.owner'])
            ->get()
            ->map(function (FleetMember $member) use ($userId) {
                $clientId = $member->fleet->owner_user_id;

                $rideStats = Ride::query()
                    ->where('driver_user_id', $userId)
                    ->where('client_user_id', $clientId)
                    ->where('status', 'completed')
                    ->selectRaw('count(*) as rides_count, max(completed_at) as last_ride_at')
                    ->first();

                return array_merge($member->toArray(), $this->clientReviewStats($clientId), [
                    'rides_together_count' => (int) $rideStats->rides_count,
                    'last_ride_at' => $rideStats->last_ride_at ? Carbon::parse($rideStats->last_ride_at) : null,
                    'joined_at' => $member->joined_at,
                ]);
            });

        $newSince = now()->subDays(30);
        $activeMembershipStats = [
            'total' => $allMemberships->count(),
            'nuevos' => $allMemberships->filter(fn ($m) => $m['joined_at'] && $m['joined_at'] >= $newSince)->count(),
            'con_carreras' => $allMemberships->filter(fn ($m) => $m['rides_together_count'] > 0)->count(),
            'sin_carreras' => $allMemberships->filter(fn ($m) => $m['rides_together_count'] === 0)->count(),
        ];

        $activeMemberships = $this->filterAndSortMemberships($allMemberships, $filter, $sort, $newSince);

        $perPage = 10;
        $page = max(1, $page);
        $paginatedMemberships = new LengthAwarePaginator(
            $activeMemberships->forPage($page, $perPage)->values(),
            $activeMemberships->count(),
            $perPage,
            $page,
        );

        $limits = $this->planLimits->forDriver($driver);

        return [
            'pendingInvitations' => $pendingInvitations,
            'activeMemberships' => $paginatedMemberships,
            'activeMembershipStats' => $activeMembershipStats,
            'maxClients' => $limits['max_clients'],
            'planCode' => $limits['plan_code'],
            'planName' => $limits['plan_name'],
            'inviteCode' => $driver->driverProfile?->invite_code,
        ];
    }

    /**
     * SOLO por código de socio (pedido explícito del usuario: "limitemos la
     * búsqueda por código nada más, porque chocarían con millones de
     * personas") — mismo criterio que FleetController::searchDrivers(), del
     * otro lado.
     */
    public function searchClients(User $driver, string $term): Collection
    {
        $memberCode = ctype_digit($term) ? (int) $term : null;
        $driverId = $driver->id;

        $clients = User::query()
            ->where('id', '!=', $driverId)
            ->where('role', 'cliente')
            ->with('city')
            ->when($memberCode, fn ($query) => $query->where('member_code', $memberCode), fn ($query) => $query->whereRaw('1 = 0'))
            ->limit(10)
            ->get();

        // Un cliente puede tener más de una flota — el estado de este
        // conductor respecto a un cliente se calcula sobre CUALQUIERA de
        // sus flotas, no una puntual.
        $clientIds = $clients->pluck('id');
        $fleetsByOwner = Fleet::query()->whereIn('owner_user_id', $clientIds)->get(['id', 'owner_user_id'])->groupBy('owner_user_id');
        $allFleetIds = $fleetsByOwner->flatten()->pluck('id');

        $memberFleetIds = FleetMember::query()
            ->where('driver_user_id', $driverId)
            ->whereNull('left_at')
            ->whereIn('fleet_id', $allFleetIds)
            ->pluck('fleet_id');

        $pendingFleetIds = FleetInvitation::query()
            ->where('driver_user_id', $driverId)
            ->where('status', 'pending')
            ->whereIn('fleet_id', $allFleetIds)
            ->pluck('fleet_id');

        $ratings = Review::query()
            ->whereIn('reviewee_user_id', $clientIds)
            ->selectRaw('reviewee_user_id, avg(rating) as avg_rating, count(*) as review_count')
            ->groupBy('reviewee_user_id')
            ->get()
            ->keyBy('reviewee_user_id');

        return $clients->map(function (User $client) use ($fleetsByOwner, $memberFleetIds, $pendingFleetIds, $ratings) {
            $clientFleetIds = ($fleetsByOwner->get($client->id) ?? collect())->pluck('id');
            $rating = $ratings->get($client->id);

            return [
                'user_id' => $client->id,
                'name' => $client->name,
                'avatar_url' => $client->avatar_url,
                'city' => $client->city?->name,
                'username' => $client->username,
                'member_code' => $client->member_code,
                'average_rating' => $rating ? round((float) $rating->avg_rating, 1) : null,
                'review_count' => $rating->review_count ?? 0,
                'status' => match (true) {
                    $clientFleetIds->intersect($memberFleetIds)->isNotEmpty() => 'member',
                    $clientFleetIds->intersect($pendingFleetIds)->isNotEmpty() => 'pending',
                    default => 'not_invited',
                },
            ];
        })->values();
    }

    private function filterAndSortMemberships(Collection $memberships, ?string $filter, ?string $sort, \DateTimeInterface $newSince): Collection
    {
        $filtered = match ($filter) {
            'nuevos' => $memberships->filter(fn ($m) => $m['joined_at'] && $m['joined_at'] >= $newSince),
            'con_carreras' => $memberships->filter(fn ($m) => $m['rides_together_count'] > 0),
            'sin_carreras' => $memberships->filter(fn ($m) => $m['rides_together_count'] === 0),
            default => $memberships,
        };

        return match ($sort) {
            'carreras' => $filtered->sortByDesc(fn ($m) => $m['rides_together_count']),
            default => $filtered->sortByDesc(fn ($m) => $m['last_ride_at'] ?? $m['joined_at']),
        };
    }

    /**
     * @return array{client_rating: float|null, client_review_count: int, client_category: string}
     */
    private function clientReviewStats(int $clientId): array
    {
        $stats = Review::query()
            ->where('reviewee_user_id', $clientId)
            ->selectRaw('avg(rating) as avg_rating, count(*) as review_count')
            ->first();

        $rating = $stats->review_count > 0 ? round((float) $stats->avg_rating, 1) : null;

        return [
            'client_rating' => $rating,
            'client_review_count' => (int) $stats->review_count,
            'client_category' => DriverCategory::forRating($rating ?? 0, (int) $stats->review_count),
        ];
    }

    /**
     * Personas que explican la confianza detrás de una invitación de flota.
     *
     * Solo mostramos clientes ACTIVOS del conductor que, además, tengan una
     * conexión ACEPTADA con el cliente que envió la invitación. No se exponen
     * teléfonos, correos ni relaciones pendientes; el conductor ya conoce a
     * estas personas porque forman parte de sus propias flotas.
     *
     * @return array<int, array{mutual_clients_count: int, mutual_clients: array<int, array<string, mixed>>}>
     */
    private function mutualClientsForInvitations(User $driver, Collection $invitations): array
    {
        $invitingClientIds = $invitations
            ->pluck('fleet.owner_user_id')
            ->filter()
            ->unique()
            ->values();

        if ($invitingClientIds->isEmpty()) {
            return [];
        }

        $driverClientIds = FleetMember::query()
            ->where('driver_user_id', $driver->id)
            ->whereNull('left_at')
            ->with('fleet:id,owner_user_id')
            ->get()
            ->pluck('fleet.owner_user_id')
            ->filter()
            ->unique()
            ->values();

        if ($driverClientIds->isEmpty()) {
            return [];
        }

        $connections = TrustCircleConnection::query()
            ->where('status', 'accepted')
            ->where(fn ($query) => $query
                ->whereIn('requester_user_id', $invitingClientIds)
                ->orWhereIn('addressee_user_id', $invitingClientIds))
            ->get(['requester_user_id', 'addressee_user_id']);

        $mutualIdsByClient = $invitingClientIds->mapWithKeys(function (int $clientId) use ($connections, $driverClientIds) {
            $connectedIds = $connections
                ->filter(fn (TrustCircleConnection $connection) => $connection->requester_user_id === $clientId
                    || $connection->addressee_user_id === $clientId)
                ->map(fn (TrustCircleConnection $connection) => $connection->requester_user_id === $clientId
                    ? $connection->addressee_user_id
                    : $connection->requester_user_id)
                ->intersect($driverClientIds)
                ->unique()
                ->values();

            return [$clientId => $connectedIds];
        });

        $people = User::query()
            ->whereIn('id', $mutualIdsByClient->flatten()->unique())
            ->get()
            ->keyBy('id');

        return $mutualIdsByClient->map(fn (Collection $ids) => [
            'mutual_clients_count' => $ids->count(),
            'mutual_clients' => $ids
                ->map(fn (int $id) => $people->get($id))
                ->filter()
                ->take(6)
                ->map(fn (User $person) => [
                    'public_id' => $person->public_id,
                    'name' => $person->full_name,
                    'username' => $person->username,
                    'avatar_url' => $person->avatar_url,
                ])
                ->values()
                ->all(),
        ])->all();
    }
}
