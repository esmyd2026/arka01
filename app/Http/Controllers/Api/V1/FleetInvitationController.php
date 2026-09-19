<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Fleet;
use App\Models\FleetInvitation;
use App\Services\Fleet\FleetInvitationManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Recomendar mi flota" a un amigo, y cancelar invitaciones, desde la app
 * móvil (roadmap app móvil, "full backend" — paridad con la web). Reusa
 * App\Services\Fleet\FleetInvitationManager, la misma lógica que
 * FleetInvitationController (web).
 */
class FleetInvitationController extends Controller
{
    public function __construct(private readonly FleetInvitationManager $invitationManager) {}

    /**
     * El conductor le manda una solicitud a un cliente puntual para unirse
     * a su flota (dirección opuesta a la invitación normal).
     */
    public function storeFromDriver(Request $request): JsonResponse
    {
        abort_unless($request->user()->isDriver(), 403);

        $validated = $request->validate([
            'client_user_id' => ['required', 'integer', 'exists:users,id'],
            'message' => ['nullable', 'string', 'max:255'],
        ]);

        $invitation = $this->invitationManager->createFromDriver($request->user(), (int) $validated['client_user_id'], $validated['message'] ?? null);

        return response()->json(['id' => $invitation->id, 'status' => $invitation->status], 201);
    }

    public function searchFriends(Request $request, Fleet $fleet): JsonResponse
    {
        $this->authorize('update', $fleet);

        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        return response()->json(['friends' => $this->invitationManager->searchFriends($request->user(), $validated['q'])]);
    }

    public function storeReferral(Request $request, Fleet $fleet): JsonResponse
    {
        $this->authorize('update', $fleet);

        $validated = $request->validate([
            'friend_user_id' => ['required', 'integer', 'exists:users,id'],
            'driver_user_ids' => ['required', 'array', 'min:1'],
            'driver_user_ids.*' => ['integer', 'exists:users,id'],
            'message' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->invitationManager->sendReferral(
            $fleet,
            $request->user(),
            (int) $validated['friend_user_id'],
            $validated['driver_user_ids'],
            $validated['message'] ?? null,
        );

        return response()->json([
            'sent' => $result['sent'],
            'skipped' => $result['skipped'],
            'friend_name' => $result['friend']->name,
        ], 201);
    }

    public function destroy(FleetInvitation $invitation): JsonResponse
    {
        $this->authorize('cancel', $invitation);

        $this->invitationManager->cancel($invitation);

        return response()->json(['message' => 'Invitación cancelada.']);
    }
}
