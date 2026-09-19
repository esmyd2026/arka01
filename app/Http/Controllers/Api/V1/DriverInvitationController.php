<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FleetInvitation;
use App\Models\FleetMember;
use App\Services\Driver\DriverClientFinder;
use App\Services\Fleet\FleetInvitationManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Mis clientes de confianza" desde la app móvil (roadmap app móvil, "full
 * backend" — paridad con la web). Reusa App\Services\Driver\DriverClientFinder
 * y App\Services\Fleet\FleetInvitationManager, la misma lógica que
 * DriverInvitationController (web).
 */
class DriverInvitationController extends Controller
{
    public function __construct(
        private readonly DriverClientFinder $clientFinder,
        private readonly FleetInvitationManager $invitationManager,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $this->clientFinder->myClients(
            $request->user(),
            $request->string('filter')->value() ?: null,
            $request->string('sort')->value() ?: null,
            (int) $request->input('page', 1),
        );

        return response()->json([
            'pending_invitations' => $data['pendingInvitations'],
            'active_memberships' => $data['activeMemberships']->items(),
            'current_page' => $data['activeMemberships']->currentPage(),
            'last_page' => $data['activeMemberships']->lastPage(),
            'active_membership_stats' => $data['activeMembershipStats'],
            'max_clients' => $data['maxClients'],
            'plan_code' => $data['planCode'],
            'plan_name' => $data['planName'],
            'invite_code' => $data['inviteCode'],
        ]);
    }

    public function searchClients(Request $request): JsonResponse
    {
        abort_unless($request->user()->isDriver(), 403);

        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        return response()->json(['clients' => $this->clientFinder->searchClients($request->user(), $validated['q'])]);
    }

    public function accept(FleetInvitation $invitation): JsonResponse
    {
        $this->authorize('respond', $invitation);

        $this->invitationManager->accept($invitation);

        return response()->json(['invitation' => $invitation->fresh()]);
    }

    public function reject(FleetInvitation $invitation): JsonResponse
    {
        $this->authorize('respond', $invitation);

        $this->invitationManager->reject($invitation);

        return response()->json(['invitation' => $invitation->fresh()]);
    }

    public function leave(Request $request, FleetMember $member): JsonResponse
    {
        $this->invitationManager->leave($member, $request->user());

        return response()->json(['message' => 'Saliste de esa flota.']);
    }

    public function toggleRequests(Request $request, FleetMember $member): JsonResponse
    {
        $this->invitationManager->toggleRequests($member, $request->user());

        return response()->json(['requests_disabled' => (bool) $member->fresh()->requests_disabled]);
    }
}
