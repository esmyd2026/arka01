<?php

namespace App\Http\Controllers;

use App\Models\FleetInvitation;
use App\Models\FleetMember;
use App\Models\Review;
use App\Models\Ride;
use App\Services\DriverCategory;
use App\Services\PlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DriverInvitationController extends Controller
{
    public function __construct(private readonly PlanLimits $planLimits) {}

    /**
     * "Mis clientes de confianza" (sección 9.5-B): invitaciones pendientes que
     * recibió el conductor y las flotas a las que ya pertenece.
     */
    public function index(Request $request): Response
    {
        $userId = $request->user()->id;

        // Pedido explícito del usuario: la tarjeta de una invitación pendiente
        // se veía pelada (solo nombre) al lado de las fichas ricas de "Flotas
        // a las que pertenecés" (foto, calificación, medalla) — mismo cálculo
        // de acá abajo (clientReviewStats()), sin el historial de carreras
        // juntos porque todavía no hicieron ninguna.
        $pendingInvitations = FleetInvitation::query()
            ->where('driver_user_id', $userId)
            ->where('status', 'pending')
            ->with(['fleet.owner'])
            ->latest()
            ->get()
            ->map(fn (FleetInvitation $invitation) => array_merge(
                $invitation->toArray(),
                $this->clientReviewStats($invitation->fleet->owner_user_id)
            ));

        // Ficha de cada cliente de confianza (pedido explícito del usuario:
        // foto, puntos, cuántas carreras le hizo, su último viaje y su
        // categoría) — antes solo se veía el nombre. A esta escala (un
        // conductor difícilmente pasa de unas pocas decenas de clientes de
        // confianza, sección 7.2 pone tope a la mayoría de los planes)
        // alcanza con traer todo a memoria y filtrar/ordenar/paginar ahí —
        // mismo criterio que ya usa DriverDirectoryController::index().
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
                    // Carbon de verdad, no el string crudo de la consulta —
                    // hace falta comparar fechas para el filtro/orden de acá
                    // abajo. joined_at también se pisa por lo mismo: el
                    // toArray() de arriba ya lo había vuelto string (formato
                    // distinto al de esta consulta cruda), mezclar los dos
                    // formatos en un mismo orden daría resultados mal.
                    'last_ride_at' => $rideStats->last_ride_at ? Carbon::parse($rideStats->last_ride_at) : null,
                    'joined_at' => $member->joined_at,
                ]);
            });

        // Pedido explícito del usuario: "indicale cuántos tiene, nuevos, con
        // carreras, sin carreras" — sobre el total sin filtrar, para que los
        // contadores no cambien cuando se aplica un filtro (si no, "Nuevos"
        // dejaría de tener sentido apenas se lo toca).
        $newSince = now()->subDays(30);
        $activeMembershipStats = [
            'total' => $allMemberships->count(),
            'nuevos' => $allMemberships->filter(fn ($m) => $m['joined_at'] && $m['joined_at'] >= $newSince)->count(),
            'con_carreras' => $allMemberships->filter(fn ($m) => $m['rides_together_count'] > 0)->count(),
            'sin_carreras' => $allMemberships->filter(fn ($m) => $m['rides_together_count'] === 0)->count(),
        ];

        $activeMemberships = $this->filterAndSortMemberships($allMemberships, $request, $newSince);

        $perPage = 10;
        $page = max(1, (int) $request->input('page', 1));
        $paginatedMemberships = new LengthAwarePaginator(
            $activeMemberships->forPage($page, $perPage)->values(),
            $activeMemberships->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        $limits = $this->planLimits->forDriver($request->user());

        return Inertia::render('Driver/Invitations', [
            'pendingInvitations' => $pendingInvitations,
            'activeMemberships' => $paginatedMemberships,
            'activeMembershipStats' => $activeMembershipStats,
            'filters' => $request->only(['filter', 'sort']),
            // null = sin límite (plan Institucional sin cupo pactado).
            'maxClients' => $limits['max_clients'],
            'planCode' => $limits['plan_code'],
            'planName' => $limits['plan_name'],
            'activeClientCount' => $activeMembershipStats['total'],
            // Pedido explícito del usuario: que el conductor pueda invitar por
            // WhatsApp a un cliente a que lo sume a su flota — mismo
            // `invite_code` que ya usa "Referí a tu conductor"
            // (ReferralController), solo que ahora con un botón de compartir
            // en vez de tener que copiar el código pelado a mano.
            'inviteCode' => $request->user()->driverProfile?->invite_code,
        ]);
    }

    /**
     * Filtro (pedido explícito del usuario: "nuevos, con carreras, sin
     * carrera") y orden (por defecto descendente, lo más reciente primero)
     * de "Flotas a las que pertenecés" — separado de index() para no
     * mezclar el armado de la ficha con esta parte.
     */
    private function filterAndSortMemberships(Collection $memberships, Request $request, \DateTimeInterface $newSince): Collection
    {
        $filtered = match ($request->string('filter')->value()) {
            'nuevos' => $memberships->filter(fn ($m) => $m['joined_at'] && $m['joined_at'] >= $newSince),
            'con_carreras' => $memberships->filter(fn ($m) => $m['rides_together_count'] > 0),
            'sin_carreras' => $memberships->filter(fn ($m) => $m['rides_together_count'] === 0),
            default => $memberships,
        };

        return match ($request->string('sort')->value()) {
            // Nulls-last a mano: sortByDesc trata null como "menor que
            // cualquier cosa" en PHP, así que ya quedan al final solos.
            'carreras' => $filtered->sortByDesc(fn ($m) => $m['rides_together_count']),
            default => $filtered->sortByDesc(fn ($m) => $m['last_ride_at'] ?? $m['joined_at']),
        };
    }

    /**
     * Calificación, cantidad de reseñas y categoría de un cliente — mismo
     * cálculo para una invitación pendiente y para una flota activa, así las
     * dos tarjetas de "Mis clientes de confianza" se ven consistentes.
     *
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
     * El conductor acepta una invitación: recién ahí queda vinculado a la flota
     * del cliente (sección 3.2 — nadie entra sin su consentimiento).
     */
    public function accept(Request $request, FleetInvitation $invitation): RedirectResponse
    {
        $this->authorize('respond', $invitation);

        if ($invitation->status !== 'pending') {
            throw ValidationException::withMessages([
                'invitation' => 'Esa invitación ya no está pendiente.',
            ]);
        }

        // Cupo de clientes de confianza según el plan vigente del conductor
        // (sección 7.2 y 9.6). Bloquea la aceptación y sugiere subir de plan.
        $maxClients = $this->planLimits->forDriver($request->user())['max_clients'];

        $activeClientCount = FleetMember::query()
            ->where('driver_user_id', $request->user()->id)
            ->whereNull('left_at')
            ->count();

        if ($maxClients !== null && $activeClientCount >= $maxClients) {
            throw ValidationException::withMessages([
                'invitation' => 'Llegó al límite de clientes de confianza de su plan. Suba de plan para aceptar más.',
            ]);
        }

        $invitation->update([
            'status' => 'accepted',
            'responded_at' => now(),
        ]);

        FleetMember::query()->create([
            'fleet_id' => $invitation->fleet_id,
            'driver_user_id' => $invitation->driver_user_id,
            'added_by' => $invitation->invited_by,
            'joined_at' => now(),
        ]);

        return back();
    }

    /**
     * El conductor rechaza una invitación.
     */
    public function reject(FleetInvitation $invitation): RedirectResponse
    {
        $this->authorize('respond', $invitation);

        if ($invitation->status !== 'pending') {
            throw ValidationException::withMessages([
                'invitation' => 'Esa invitación ya no está pendiente.',
            ]);
        }

        $invitation->update([
            'status' => 'rejected',
            'responded_at' => now(),
        ]);

        return back();
    }

    /**
     * El conductor se sale por su cuenta de una flota a la que pertenece
     * (sección 3.2 y 3.3 — cualquiera de las dos partes puede terminar la relación).
     */
    public function leave(Request $request, FleetMember $member): RedirectResponse
    {
        if ($member->driver_user_id !== $request->user()->id) {
            abort(403);
        }

        if (! $member->isActive()) {
            throw ValidationException::withMessages([
                'member' => 'Ya no forma parte de esa flota.',
            ]);
        }

        $member->update([
            'left_at' => now(),
            'left_reason' => 'driver_left',
        ]);

        return back();
    }

    /**
     * El conductor deja de recibir solicitudes de un cliente puntual sin
     * cortar la relación entera (pedido explícito del usuario) — sigue
     * siendo parte de su flota, solo que sus pedidos ya no le llegan.
     */
    public function toggleRequests(Request $request, FleetMember $member): RedirectResponse
    {
        if ($member->driver_user_id !== $request->user()->id) {
            abort(403);
        }

        $member->update(['requests_disabled' => ! $member->requests_disabled]);

        return back();
    }
}
