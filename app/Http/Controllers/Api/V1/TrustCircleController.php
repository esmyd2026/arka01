<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TrustCircleConnection;
use App\Services\Trust\TrustCircleManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Círculo de confianza desde la app móvil (roadmap Hito 5B, paridad con la
 * web). Reusa App\Services\Trust\TrustCircleManager, la misma lógica que
 * TrustCircleController (web).
 */
class TrustCircleController extends Controller
{
    public function __construct(private readonly TrustCircleManager $trustCircle) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->trustCircle->overview($request->user()));
    }

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);

        return response()->json($this->trustCircle->search($request->user(), $validated['q']));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_public_id' => ['required', 'uuid', 'exists:users,public_id'],
            'relationship_label' => ['nullable', 'string', 'max:50'],
        ]);

        $connection = $this->trustCircle->store($request->user(), $validated['user_public_id'], $validated['relationship_label'] ?? null);

        return response()->json(['connection_public_id' => $connection->public_id], 201);
    }

    public function respond(Request $request, TrustCircleConnection $connection): JsonResponse
    {
        $validated = $request->validate(['action' => ['required', Rule::in(['accept', 'reject'])]]);

        $this->trustCircle->respond($connection, $request->user(), $validated['action']);

        return response()->json([
            'message' => $validated['action'] === 'accept' ? 'Persona agregada a tu círculo.' : 'Solicitud rechazada.',
        ]);
    }

    public function updateSettings(Request $request, TrustCircleConnection $connection): JsonResponse
    {
        $validated = $request->validate([
            'relationship_label' => ['nullable', 'string', 'max:50'],
            'share_fleet' => ['required', 'boolean'],
            'share_rating' => ['required', 'boolean'],
        ]);

        $this->trustCircle->updateSettings($connection, $request->user(), $validated);

        return response()->json(['message' => 'Privacidad actualizada.']);
    }

    public function destroy(Request $request, TrustCircleConnection $connection): JsonResponse
    {
        $this->trustCircle->destroy($connection, $request->user());

        return response()->json(['message' => 'Conexión eliminada del círculo.']);
    }

    public function inviteDriver(Request $request): JsonResponse
    {
        $validated = $request->validate(['driver_public_id' => ['required', 'uuid', 'exists:users,public_id']]);

        $this->trustCircle->inviteDriver($request->user(), $validated['driver_public_id']);

        return response()->json(['message' => 'Invitación enviada al conductor. Entrará a tu flota cuando la acepte.']);
    }
}
