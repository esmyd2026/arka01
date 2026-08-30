<?php

namespace App\Http\Controllers;

use App\Models\FleetInvitation;
use App\Models\FleetMember;
use App\Services\Driver\DriverClientFinder;
use App\Services\Fleet\FleetInvitationManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lógica real en App\Services\Driver\DriverClientFinder (listar/buscar) y
 * App\Services\Fleet\FleetInvitationManager (aceptar/rechazar/salirse) —
 * roadmap app móvil, "full backend".
 */
class DriverInvitationController extends Controller
{
    public function __construct(
        private readonly DriverClientFinder $clientFinder,
        private readonly FleetInvitationManager $invitationManager,
    ) {}

    /**
     * "Mis clientes de confianza" (sección 9.5-B): invitaciones pendientes que
     * recibió el conductor y las flotas a las que ya pertenece.
     */
    public function index(Request $request): Response
    {
        $data = $this->clientFinder->myClients(
            $request->user(),
            $request->string('filter')->value() ?: null,
            $request->string('sort')->value() ?: null,
            (int) $request->input('page', 1),
        );

        return Inertia::render('Driver/Invitations', [
            'pendingInvitations' => $data['pendingInvitations'],
            'activeMemberships' => $data['activeMemberships'],
            'activeMembershipStats' => $data['activeMembershipStats'],
            'filters' => $request->only(['filter', 'sort']),
            'maxClients' => $data['maxClients'],
            'planCode' => $data['planCode'],
            'planName' => $data['planName'],
            'activeClientCount' => $data['activeMembershipStats']['total'],
            'inviteCode' => $data['inviteCode'],
        ]);
    }

    /**
     * Pedido explícito del usuario: "los conductores que puedan mandar
     * invitación mediante un buscador de clientes que existen".
     */
    public function searchClients(Request $request)
    {
        abort_unless($request->user()->isDriver(), 403);

        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        return response()->json(['clients' => $this->clientFinder->searchClients($request->user(), $validated['q'])]);
    }

    public function accept(FleetInvitation $invitation): RedirectResponse
    {
        $this->authorize('respond', $invitation);

        $this->invitationManager->accept($invitation);

        return back();
    }

    public function reject(FleetInvitation $invitation): RedirectResponse
    {
        $this->authorize('respond', $invitation);

        $this->invitationManager->reject($invitation);

        return back();
    }

    /**
     * El conductor se sale por su cuenta de una flota a la que pertenece.
     */
    public function leave(Request $request, FleetMember $member): RedirectResponse
    {
        $this->invitationManager->leave($member, $request->user());

        return back();
    }

    /**
     * El conductor deja de recibir solicitudes de un cliente puntual sin
     * cortar la relación entera.
     */
    public function toggleRequests(Request $request, FleetMember $member): RedirectResponse
    {
        $this->invitationManager->toggleRequests($member, $request->user());

        return back();
    }
}
