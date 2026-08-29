<?php

namespace App\Http\Controllers;

use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\TrustCircleConnection;
use App\Models\User;
use App\Notifications\TrustCircleRequestPushNotification;
use App\Notifications\TrustCircleResponsePushNotification;
use App\Services\Fleet\FleetInvitationCreator;
use App\Services\Trust\TrustIndexCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TrustCircleController extends Controller
{
    public function __construct(
        private readonly TrustIndexCalculator $trustIndex,
        private readonly FleetInvitationCreator $fleetInvitationCreator,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $this->ensurePersonalAccount($user);

        $connections = TrustCircleConnection::query()
            ->where('status', 'accepted')
            ->where(fn ($query) => $query
                ->where('requester_user_id', $user->id)
                ->orWhere('addressee_user_id', $user->id))
            ->with(['requester', 'addressee', 'settings'])
            ->latest('responded_at')
            ->limit(100)
            ->get();

        $ownDriverIds = $this->fleetDriverIds($user->id);

        $people = $connections->map(function (TrustCircleConnection $connection) use ($user, $ownDriverIds) {
            $person = $connection->otherUser($user);
            $mySettings = $connection->settings->firstWhere('user_id', $user->id);
            $theirSettings = $connection->settings->firstWhere('user_id', $person->id);
            $theirDriverIds = $theirSettings?->share_fleet ? $this->fleetDriverIds($person->id) : collect();

            return [
                'connection_public_id' => $connection->public_id,
                'name' => $person->full_name,
                'username' => $person->username,
                'member_code' => $person->member_code,
                'avatar_url' => $person->avatar_url,
                'role' => $person->isDriver() ? 'Conductor' : 'Cliente',
                'relationship_label' => $mySettings?->relationship_label,
                'shares_fleet' => (bool) $theirSettings?->share_fleet,
                'shares_rating' => (bool) ($theirSettings?->share_rating ?? true),
                'my_privacy' => [
                    'share_fleet' => (bool) $mySettings?->share_fleet,
                    'share_rating' => (bool) ($mySettings?->share_rating ?? true),
                ],
                'trust' => ($theirSettings?->share_rating ?? true)
                    ? $this->trustIndex->calculate($person, $user)
                    : null,
                'common_drivers' => $ownDriverIds->intersect($theirDriverIds)->count(),
                'shared_drivers_count' => $theirDriverIds->count(),
            ];
        })->values();

        $visibleFleetOwnerIds = $connections
            ->filter(function (TrustCircleConnection $connection) use ($user) {
                $person = $connection->otherUser($user);

                return (bool) $connection->settings->firstWhere('user_id', $person->id)?->share_fleet;
            })
            ->map(fn (TrustCircleConnection $connection) => $connection->otherUser($user)->id)
            ->unique()
            ->values();

        return Inertia::render('TrustCircle/Index', [
            'summary' => [
                'people' => $people->count(),
                'shared_drivers' => $people->sum('shared_drivers_count'),
                'own_trust' => $this->trustIndex->calculate($user),
            ],
            'people' => $people,
            'receivedRequests' => $this->pendingRequests($user, received: true),
            'sentRequests' => $this->pendingRequests($user, received: false),
            'recommendedDrivers' => $user->isClient()
                ? $this->recommendedDrivers($user, $visibleFleetOwnerIds, $ownDriverIds)
                : [],
            'canBuildFleet' => $user->isClient(),
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $this->ensurePersonalAccount($request->user());
        $validated = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);
        $term = ltrim(trim($validated['q']), '@#');
        $memberCode = ctype_digit($term) ? (int) $term : null;
        // Se retiran comodines SQL escritos por el usuario y cada palabra se
        // busca en nombre, apellido o usuario. Así "Doris Tapia" encuentra
        // a Doris aunque los dos datos estén guardados en columnas distintas.
        $nameTokens = collect(preg_split('/\s+/u', mb_strtolower($term), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn (string $token) => preg_replace('/[%_]+/u', '', $token))
            ->filter()
            ->take(5);

        $users = User::query()
            ->where('id', '!=', $request->user()->id)
            ->where('is_admin', false)
            ->whereDoesntHave('cooperative')
            ->when(
                $memberCode,
                fn ($query) => $query->where('member_code', $memberCode),
                function ($query) use ($nameTokens) {
                    foreach ($nameTokens as $token) {
                        $like = '%'.$token.'%';
                        $query->where(fn ($part) => $part
                            ->whereRaw('LOWER(name) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(last_name) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(username) LIKE ?', [$like]));
                    }
                }
            )
            ->orderBy('name')
            ->orderBy('last_name')
            ->limit(10)
            ->get();

        $connectedIds = $this->connectionQuery($request->user()->id)
            ->get(['requester_user_id', 'addressee_user_id', 'status'])
            ->mapWithKeys(function (TrustCircleConnection $connection) use ($request) {
                $otherId = $connection->requester_user_id === $request->user()->id
                    ? $connection->addressee_user_id
                    : $connection->requester_user_id;

                return [$otherId => $connection->status];
            });

        return response()->json(['people' => $users->map(fn (User $person) => [
            'user_public_id' => $person->public_id,
            'name' => $person->full_name,
            'username' => $person->username,
            'member_code' => $person->member_code,
            'avatar_url' => $person->avatar_url,
            'role' => $person->isDriver() ? 'Conductor' : 'Cliente',
            'connection_status' => $connectedIds->get($person->id),
        ])->values()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $this->ensurePersonalAccount($user);
        $validated = $request->validate([
            'user_public_id' => ['required', 'uuid', 'exists:users,public_id'],
            'relationship_label' => ['nullable', 'string', 'max:50'],
        ]);

        $person = User::query()->where('public_id', $validated['user_public_id'])->firstOrFail();
        abort_if($person->id === $user->id || $person->isAdmin() || $person->isCooperative(), 422);

        $existing = $this->connectionQuery($user->id)
            ->where(fn ($query) => $query
                ->where('requester_user_id', $person->id)
                ->orWhere('addressee_user_id', $person->id))
            ->first();

        if ($existing && $existing->status !== 'rejected') {
            throw ValidationException::withMessages(['person' => 'Ya existe una conexión o solicitud pendiente con esta persona.']);
        }

        if ($this->connectionQuery($user->id)->whereIn('status', ['pending', 'accepted'])->count() >= 100) {
            throw ValidationException::withMessages(['person' => 'Tu círculo llegó al máximo de 100 conexiones.']);
        }

        $connection = DB::transaction(function () use ($existing, $user, $person, $validated) {
            $connection = $existing ?? new TrustCircleConnection;
            $connection->fill([
                'requester_user_id' => $user->id,
                'addressee_user_id' => $person->id,
                'status' => 'pending',
                'responded_at' => null,
            ])->save();

            $connection->settings()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'relationship_label' => $validated['relationship_label'] ?? null,
                    'share_fleet' => false,
                    'share_rating' => true,
                ]
            );

            return $connection;
        });

        // Web Push conserva el aviso aun con la pestaña cerrada. Se envía
        // después del commit para que el worker nunca lea una fila incompleta.
        $person->notify(new TrustCircleRequestPushNotification($connection->load('requester')));

        return back()->with('status', 'Solicitud enviada. La persona debe aceptarla antes de entrar a tu círculo.');
    }

    public function respond(Request $request, TrustCircleConnection $connection): RedirectResponse
    {
        abort_unless($connection->addressee_user_id === $request->user()->id && $connection->status === 'pending', 403);
        $validated = $request->validate(['action' => ['required', Rule::in(['accept', 'reject'])]]);

        DB::transaction(function () use ($connection, $validated) {
            $connection->update([
                'status' => $validated['action'] === 'accept' ? 'accepted' : 'rejected',
                'responded_at' => now(),
            ]);

            if ($validated['action'] === 'accept') {
                foreach ([$connection->requester_user_id, $connection->addressee_user_id] as $userId) {
                    $connection->settings()->firstOrCreate(
                        ['user_id' => $userId],
                        ['share_fleet' => false, 'share_rating' => true]
                    );
                }
            }
        });

        $connection->load(['requester', 'addressee']);
        $connection->requester->notify(new TrustCircleResponsePushNotification(
            $connection,
            $validated['action'] === 'accept',
        ));

        return back()->with('status', $validated['action'] === 'accept' ? 'Persona agregada a tu círculo.' : 'Solicitud rechazada.');
    }

    public function updateSettings(Request $request, TrustCircleConnection $connection): RedirectResponse
    {
        abort_unless($connection->includes($request->user()) && $connection->status === 'accepted', 403);
        $validated = $request->validate([
            'relationship_label' => ['nullable', 'string', 'max:50'],
            'share_fleet' => ['required', 'boolean'],
            'share_rating' => ['required', 'boolean'],
        ]);

        $connection->settings()->updateOrCreate(['user_id' => $request->user()->id], $validated);

        return back()->with('status', 'Privacidad actualizada.');
    }

    public function destroy(Request $request, TrustCircleConnection $connection): RedirectResponse
    {
        abort_unless($connection->includes($request->user()), 403);
        $connection->delete();

        return back()->with('status', 'Conexión eliminada del círculo.');
    }

    public function inviteDriver(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isClient(), 403);
        $validated = $request->validate(['driver_public_id' => ['required', 'uuid', 'exists:users,public_id']]);
        $driver = User::query()->where('public_id', $validated['driver_public_id'])->firstOrFail();
        abort_unless($driver->isDriver(), 422);

        $visibleOwnerIds = $this->visibleFleetOwnerIds($user);
        $isSharedDriver = FleetMember::query()
            ->where('driver_user_id', $driver->id)
            ->whereNull('left_at')
            ->whereHas('fleet', fn ($query) => $query->whereIn('owner_user_id', $visibleOwnerIds))
            ->exists();
        abort_unless($isSharedDriver, 403);

        $fleet = Fleet::query()->where('owner_user_id', $user->id)->orderBy('id')->first()
            ?? Fleet::query()->create(['owner_user_id' => $user->id, 'name' => 'Mi flota']);

        $this->fleetInvitationCreator->create(
            $fleet,
            $driver->id,
            $user->id,
            'referral',
            'Te encontré por una recomendación de mi círculo de confianza.'
        );

        return back()->with('status', 'Invitación enviada al conductor. Entrará a tu flota cuando la acepte.');
    }

    private function pendingRequests(User $user, bool $received): array
    {
        $column = $received ? 'addressee_user_id' : 'requester_user_id';

        return TrustCircleConnection::query()
            ->where($column, $user->id)
            ->where('status', 'pending')
            ->with(['requester', 'addressee', 'settings'])
            ->latest()
            ->get()
            ->map(function (TrustCircleConnection $connection) use ($user) {
                $person = $connection->otherUser($user);

                return [
                    'connection_public_id' => $connection->public_id,
                    'user_public_id' => $person->public_id,
                    'name' => $person->full_name,
                    'username' => $person->username,
                    'member_code' => $person->member_code,
                    'avatar_url' => $person->avatar_url,
                    'role' => $person->isDriver() ? 'Conductor' : 'Cliente',
                    'relationship_label' => $connection->settings->firstWhere('user_id', $person->id)?->relationship_label,
                    'trust' => $this->trustIndex->calculate($person, $user),
                ];
            })->values()->all();
    }

    private function recommendedDrivers(User $user, $visibleOwnerIds, $ownDriverIds): array
    {
        if ($visibleOwnerIds->isEmpty()) {
            return [];
        }

        return FleetMember::query()
            ->whereNull('left_at')
            ->whereNotIn('driver_user_id', $ownDriverIds)
            ->whereHas('fleet', fn ($query) => $query->whereIn('owner_user_id', $visibleOwnerIds))
            ->with(['fleet.owner', 'driver.driverProfile'])
            ->get()
            ->filter(fn (FleetMember $member) => $member->driver?->driverProfile && ! $member->driver->driverProfile->deactivated_at)
            ->groupBy('driver_user_id')
            ->map(function ($members) use ($user) {
                $driver = $members->first()->driver;
                $sources = $members->pluck('fleet.owner.full_name')->filter()->unique()->values();

                return [
                    'driver_public_id' => $driver->public_id,
                    'name' => $driver->full_name,
                    'username' => $driver->username,
                    'member_code' => $driver->member_code,
                    'avatar_url' => $driver->avatar_url,
                    'recommended_by_count' => $sources->count(),
                    'recommended_by' => $sources->take(3)->all(),
                    'trust' => $this->trustIndex->calculate($driver, $user),
                ];
            })
            ->sortByDesc(fn ($driver) => [$driver['recommended_by_count'], $driver['trust']['score']])
            ->values()
            ->take(20)
            ->all();
    }

    private function visibleFleetOwnerIds(User $user)
    {
        return TrustCircleConnection::query()
            ->where('status', 'accepted')
            ->where(fn ($query) => $query
                ->where('requester_user_id', $user->id)
                ->orWhere('addressee_user_id', $user->id))
            ->with('settings')
            ->get()
            ->filter(function (TrustCircleConnection $connection) use ($user) {
                $otherId = $connection->requester_user_id === $user->id
                    ? $connection->addressee_user_id
                    : $connection->requester_user_id;

                return (bool) $connection->settings->firstWhere('user_id', $otherId)?->share_fleet;
            })
            ->map(fn (TrustCircleConnection $connection) => $connection->requester_user_id === $user->id
                ? $connection->addressee_user_id
                : $connection->requester_user_id)
            ->values();
    }

    private function fleetDriverIds(int $ownerUserId)
    {
        return FleetMember::query()
            ->whereNull('left_at')
            ->whereHas('fleet', fn ($query) => $query->where('owner_user_id', $ownerUserId))
            ->pluck('driver_user_id')
            ->unique()
            ->values();
    }

    private function connectionQuery(int $userId)
    {
        return TrustCircleConnection::query()->where(fn ($query) => $query
            ->where('requester_user_id', $userId)
            ->orWhere('addressee_user_id', $userId));
    }

    private function ensurePersonalAccount(User $user): void
    {
        abort_if($user->isAdmin() || $user->isCooperative(), 403);
    }
}
