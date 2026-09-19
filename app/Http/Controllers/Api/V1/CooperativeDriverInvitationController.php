<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CooperativeDriverMembership;
use App\Services\Cooperative\CooperativeDriverResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Invitaciones de cooperativas al conductor desde la app móvil (roadmap
 * Hito 5B, paridad con la web). Reusa
 * App\Services\Cooperative\CooperativeDriverResponder, la misma lógica que
 * CooperativeDriverController@invitations/@respond (web).
 */
class CooperativeDriverInvitationController extends Controller
{
    public function __construct(private readonly CooperativeDriverResponder $driverResponder) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isDriver(), 403);

        return response()->json(['memberships' => $this->driverResponder->pendingInvitations($request->user())]);
    }

    public function respond(Request $request, CooperativeDriverMembership $membership): JsonResponse
    {
        $validated = $request->validate(['decision' => ['required', 'string', 'in:accept,reject']]);

        $this->driverResponder->respond($membership, $request->user(), $validated['decision']);

        return response()->json([
            'message' => $validated['decision'] === 'accept' ? 'Vínculo con la cooperativa aceptado.' : 'Invitación rechazada.',
        ]);
    }
}
