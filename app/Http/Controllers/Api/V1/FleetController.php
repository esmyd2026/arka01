<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\FleetResource;
use App\Models\ClientCooperative;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Services\Fleet\FleetDriverSearch;
use App\Services\Fleet\FleetInvitationCreator;
use App\Services\Fleet\FleetRosterBuilder;
use App\Services\PlanLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Flotas de confianza en la app móvil (ROADMAP_APLICACION_MOVIL_CAPACITOR.md,
 * Hito 5: "Gestionar flotas e invitaciones"). Mismas reglas que la web en
 * todo: roster (FleetRosterBuilder), búsqueda (FleetDriverSearch) e
 * invitación (FleetInvitationCreator) son los mismos servicios extraídos de
 * FleetController/FleetInvitationController — ninguno de los dos canales
 * puede divergir.
 */
class FleetController extends Controller
{
    public function __construct(
        private readonly PlanLimits $planLimits,
        private readonly FleetRosterBuilder $rosterBuilder,
        private readonly FleetDriverSearch $driverSearch,
        private readonly FleetInvitationCreator $invitationCreator,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if ($request->user()->isDriver()) {
            return response()->json([
                'message' => 'Los conductores no pueden tener una flota propia.',
            ], 403);
        }

        $limits = $this->planLimits->forClient($request->user());

        $fleets = Fleet::query()
            ->where('owner_user_id', $request->user()->id)
            ->orderBy('id')
            ->get();

        // Mismo respaldo que la web (FleetController::index()): si todavía
        // no tiene ninguna, se crea "Mi flota" sola en vez de pedir un paso
        // extra la primera vez.
        if ($fleets->isEmpty()) {
            $fleets = collect([
                Fleet::query()->create([
                    'owner_user_id' => $request->user()->id,
                    'name' => 'Mi flota',
                ]),
            ]);
        }

        $attachedCooperativeIds = ClientCooperative::query()
            ->where('client_user_id', $request->user()->id)
            ->pluck('cooperative_id');

        return response()->json([
            'fleets' => FleetResource::collection(
                $fleets->map(fn (Fleet $fleet) => $this->rosterBuilder->build($fleet))
            ),
            'max_fleets' => $limits['max_fleets'],
            'max_drivers_per_fleet' => $limits['max_drivers_per_fleet'],
            'max_cooperatives' => $limits['max_cooperatives'],
            'plan_code' => $limits['plan_code'],
            'plan_name' => $limits['plan_name'],
            'has_cooperatives' => $attachedCooperativeIds->isNotEmpty(),
        ]);
    }

    /**
     * Crea una flota adicional, gateado por el cupo de flotas del plan
     * vigente — mismo mensaje/regla que FleetController::store() (web).
     */
    public function store(Request $request): JsonResponse
    {
        if ($request->user()->isDriver()) {
            throw ValidationException::withMessages([
                'name' => 'Los conductores no pueden tener una flota propia — cada cuenta es cliente o conductor, no ambas.',
            ]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $limits = $this->planLimits->forClient($request->user());
        $currentFleetCount = Fleet::query()->where('owner_user_id', $request->user()->id)->count();

        if ($limits['max_fleets'] !== null && $currentFleetCount >= $limits['max_fleets']) {
            throw ValidationException::withMessages([
                'name' => 'Llegó al límite de flotas de su plan. Suba de plan para crear otra.',
            ]);
        }

        $fleet = Fleet::query()->create([
            'owner_user_id' => $request->user()->id,
            'name' => $validated['name'],
        ]);

        return response()->json(['fleet' => new FleetResource($this->rosterBuilder->build($fleet))], 201);
    }

    public function show(Request $request, Fleet $fleet): JsonResponse
    {
        $this->authorize('view', $fleet);

        $limits = $this->planLimits->forClient($request->user());

        return response()->json([
            'fleet' => new FleetResource($this->rosterBuilder->build($fleet)),
            'max_drivers_per_fleet' => $limits['max_drivers_per_fleet'],
        ]);
    }

    public function searchDrivers(Request $request, Fleet $fleet): JsonResponse
    {
        $this->authorize('view', $fleet);

        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        return response()->json([
            'drivers' => $this->driverSearch->search($fleet, $validated['q'], $request->user()),
        ]);
    }

    public function invite(Request $request, Fleet $fleet): JsonResponse
    {
        $this->authorize('update', $fleet);

        $validated = $request->validate([
            'driver_user_id' => ['required', 'integer', 'exists:users,id'],
            'message' => ['nullable', 'string', 'max:255'],
        ]);

        $invitation = $this->invitationCreator->create(
            $fleet,
            (int) $validated['driver_user_id'],
            $request->user()->id,
            'client',
            $validated['message'] ?? null,
        );

        return response()->json(['id' => $invitation->id, 'status' => $invitation->status], 201);
    }

    public function removeMember(Request $request, FleetMember $member): JsonResponse
    {
        $this->authorize('update', $member->fleet);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $member->update([
            'left_at' => now(),
            'left_reason' => $validated['reason'] ?? null,
            'removed_by' => $request->user()->id,
        ]);

        return response()->json(['message' => 'Conductor quitado de la flota.']);
    }
}
