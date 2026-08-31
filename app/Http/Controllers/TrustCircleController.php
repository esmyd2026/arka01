<?php

namespace App\Http\Controllers;

use App\Models\TrustCircleConnection;
use App\Services\Trust\TrustCircleManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lógica real en App\Services\Trust\TrustCircleManager (roadmap app móvil,
 * "full backend").
 */
class TrustCircleController extends Controller
{
    public function __construct(private readonly TrustCircleManager $trustCircle) {}

    public function index(Request $request): Response
    {
        return Inertia::render('TrustCircle/Index', $this->trustCircle->overview($request->user()));
    }

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);

        return response()->json($this->trustCircle->search($request->user(), $validated['q']));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_public_id' => ['required', 'uuid', 'exists:users,public_id'],
            'relationship_label' => ['nullable', 'string', 'max:50'],
        ]);

        $this->trustCircle->store($request->user(), $validated['user_public_id'], $validated['relationship_label'] ?? null);

        return back()->with('status', 'Solicitud enviada. La persona debe aceptarla antes de entrar a tu círculo.');
    }

    public function respond(Request $request, TrustCircleConnection $connection): RedirectResponse
    {
        $validated = $request->validate(['action' => ['required', Rule::in(['accept', 'reject'])]]);

        $this->trustCircle->respond($connection, $request->user(), $validated['action']);

        return back()->with('status', $validated['action'] === 'accept' ? 'Persona agregada a tu círculo.' : 'Solicitud rechazada.');
    }

    public function updateSettings(Request $request, TrustCircleConnection $connection): RedirectResponse
    {
        $validated = $request->validate([
            'relationship_label' => ['nullable', 'string', 'max:50'],
            'share_fleet' => ['required', 'boolean'],
            'share_rating' => ['required', 'boolean'],
        ]);

        $this->trustCircle->updateSettings($connection, $request->user(), $validated);

        return back()->with('status', 'Privacidad actualizada.');
    }

    public function destroy(Request $request, TrustCircleConnection $connection): RedirectResponse
    {
        $this->trustCircle->destroy($connection, $request->user());

        return back()->with('status', 'Conexión eliminada del círculo.');
    }

    public function inviteDriver(Request $request): RedirectResponse
    {
        $validated = $request->validate(['driver_public_id' => ['required', 'uuid', 'exists:users,public_id']]);

        $this->trustCircle->inviteDriver($request->user(), $validated['driver_public_id']);

        return back()->with('status', 'Invitación enviada al conductor. Entrará a tu flota cuando la acepte.');
    }
}
