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

        $pendingInvitations = FleetInvitation::query()
            ->where('driver_user_id', $userId)
            ->where('status', 'pending')
            ->with(['fleet.owner'])
            ->latest()
            ->get();

        // Ficha de cada cliente de confianza (pedido explícito del usuario:
        // foto, puntos, cuántas carreras le hizo, su último viaje y su
        // categoría) — antes solo se veía el nombre.
        $activeMemberships = FleetMember::query()
            ->where('driver_user_id', $userId)
            ->whereNull('left_at')
            ->with(['fleet.owner'])
            ->get()
            ->map(function (FleetMember $member) use ($userId) {
                $clientId = $member->fleet->owner_user_id;

                $clientStats = Review::query()
                    ->where('reviewee_user_id', $clientId)
                    ->selectRaw('avg(rating) as avg_rating, count(*) as review_count')
                    ->first();

                $rideStats = Ride::query()
                    ->where('driver_user_id', $userId)
                    ->where('client_user_id', $clientId)
                    ->where('status', 'completed')
                    ->selectRaw('count(*) as rides_count, max(completed_at) as last_ride_at')
                    ->first();

                $rating = $clientStats->review_count > 0 ? round((float) $clientStats->avg_rating, 1) : null;

                return array_merge($member->toArray(), [
                    'client_rating' => $rating,
                    'client_review_count' => (int) $clientStats->review_count,
                    'client_category' => DriverCategory::forRating($rating ?? 0, (int) $clientStats->review_count),
                    'rides_together_count' => (int) $rideStats->rides_count,
                    'last_ride_at' => $rideStats->last_ride_at,
                ]);
            });

        $limits = $this->planLimits->forDriver($request->user());

        return Inertia::render('Driver/Invitations', [
            'pendingInvitations' => $pendingInvitations,
            'activeMemberships' => $activeMemberships,
            // null = sin límite (plan Institucional sin cupo pactado).
            'maxClients' => $limits['max_clients'],
            'planCode' => $limits['plan_code'],
            'planName' => $limits['plan_name'],
            'activeClientCount' => $activeMemberships->count(),
        ]);
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
