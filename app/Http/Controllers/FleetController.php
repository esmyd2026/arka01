<?php

namespace App\Http\Controllers;

use App\Models\ClientCooperative;
use App\Models\Cooperative;
use App\Models\Fleet;
use App\Services\Fleet\FleetDriverSearch;
use App\Services\Fleet\FleetRosterBuilder;
use App\Services\PlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class FleetController extends Controller
{
    /** Cada cuenta es cliente o conductor, nunca las dos (sección 3.1). */
    private const SINGLE_ROLE_MESSAGE = 'Los conductores no pueden tener una flota propia — cada cuenta es cliente o conductor, no ambas.';

    public function __construct(
        private readonly PlanLimits $planLimits,
        private readonly FleetRosterBuilder $rosterBuilder,
        private readonly FleetDriverSearch $driverSearch,
    ) {}

    /**
     * Lista de flotas del cliente (sección 3.2, 9.5-A y multi-flota de la
     * sección 7.3). Pedido explícito del usuario ("que no vaya un paso
     * más... que ahí ya salga su flota, y los botones de agregar los
     * acomodes por ahí"): antes esta pantalla era solo un resumen por flota
     * que llevaba a show() para ver/agregar conductores — ahora trae el
     * roster completo de cada una de una (mismo cálculo que antes vivía
     * SOLO en show(), extraído a fleetDetails() para no duplicarlo). En el
     * plan Gratis (el caso más común) es una sola flota, así que la
     * pantalla completa queda armada sin ningún clic de por medio.
     */
    public function index(Request $request): Response|RedirectResponse
    {
        if ($request->user()->isDriver()) {
            return redirect()->route('dashboard')->with('status', self::SINGLE_ROLE_MESSAGE);
        }

        $limits = $this->planLimits->forClient($request->user());

        $fleets = Fleet::query()
            ->where('owner_user_id', $request->user()->id)
            ->orderBy('id')
            ->get();

        // Compatibilidad con el flujo de antes de la Fase 5: si el cliente
        // todavía no tiene ninguna flota, se la creamos automáticamente para
        // no pedirle un paso extra de "crear flota" la primera vez.
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

        return Inertia::render('Fleet/List', [
            // El cupo de flotas es siempre chico (1 en el plan Gratis, un
            // puñado en Multi-flota) — cargar el roster completo de cada una
            // acá mismo no es un problema de escala real, y es justo lo que
            // hace falta para que esta pantalla ya no dependa de show().
            'fleets' => $fleets->map(fn (Fleet $fleet) => $this->rosterBuilder->build($fleet)),
            // null = sin límite de flotas.
            'maxFleets' => $limits['max_fleets'],
            'maxDriversPerFleet' => $limits['max_drivers_per_fleet'],
            // null = sin límite de cooperativas (pedido explícito del usuario:
            // el botón "Agregar" no avisaba nada al chocar con este límite —
            // CooperativeDirectoryController::attach() ya lo rechazaba, pero
            // en silencio, sin ningún mensaje en pantalla).
            'maxCooperatives' => $limits['max_cooperatives'],
            'planCode' => $limits['plan_code'],
            'planName' => $limits['plan_name'],
            // Cooperativas son una red del cliente (compartida entre sus flotas),
            // no conductores buscables por código. Se muestran separadas para
            // evitar que el buscador de invitaciones parezca servir para ambas cosas.
            'cooperatives' => Cooperative::query()
                ->where('status', 'approved')
                ->whereNull('suspended_at')
                ->withCount('activeDriverMemberships')
                ->orderBy('name')
                ->get(['id', 'public_id', 'name', 'logo_path', 'main_address', 'stand_lat', 'stand_lng'])
                ->map(fn (Cooperative $cooperative) => [
                    'id' => $cooperative->id,
                    'public_id' => $cooperative->public_id,
                    'name' => $cooperative->name,
                    'logo_url' => $cooperative->logo_url,
                    'main_address' => $cooperative->main_address,
                    'active_drivers' => $cooperative->active_driver_memberships_count,
                    'is_attached' => $attachedCooperativeIds->contains($cooperative->id),
                ]),
        ]);
    }

    /**
     * Crea una flota adicional, gateado por el cupo de flotas del plan
     * vigente del cliente (sección 7.3 y 9.6).
     */
    public function store(Request $request): RedirectResponse
    {
        if ($request->user()->isDriver()) {
            throw ValidationException::withMessages(['name' => self::SINGLE_ROLE_MESSAGE]);
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

        return redirect()->route('fleet.show', $fleet);
    }

    /**
     * Detalle de una flota puntual: sus conductores activos, invitaciones
     * pendientes y el buscador para invitar a más (sección 3.2). Desde el
     * rediseño de "Mis flotas" ya no es el camino normal (index() muestra
     * esto mismo de una para cada flota) — se deja como respaldo por si algo
     * llega a enlazar directo a una flota puntual.
     */
    public function show(Request $request, Fleet $fleet): Response
    {
        $this->authorize('view', $fleet);

        $limits = $this->planLimits->forClient($request->user());
        $details = $this->rosterBuilder->build($fleet);

        return Inertia::render('Fleet/Show', [
            'fleet' => $details['fleet'],
            // null = sin límite de conductores por flota.
            'maxDriversPerFleet' => $limits['max_drivers_per_fleet'],
            'memberStats' => $details['memberStats'],
        ]);
    }

    /**
     * Búsqueda de conductores para invitar a esta flota puntual (sección
     * 3.2). Antes era SOLO por código de socio o de invitación (pedido
     * explícito del usuario, tras una captura real de varias personas de
     * apellido "Cedeño" saliendo juntas en una búsqueda por nombre). Pedido
     * explícito del usuario esta vuelta: "permite que puedan buscar por
     * nombres y apellidos y usuario y codigo" — se vuelve a sumar nombre,
     * apellido y usuario, con el mismo límite de 10 resultados de siempre
     * (varios "Cedeño" ya no chocan entre sí: se ven junto a foto, código y
     * calificación, no hace falta que el nombre sea único). El teléfono
     * sigue sin exponerse en los resultados, eso no cambió. Devuelve JSON
     * porque se consume desde un buscador con resultados en vivo.
     */
    public function searchDrivers(Request $request, Fleet $fleet)
    {
        $this->authorize('view', $fleet);

        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $results = $this->driverSearch->search($fleet, $validated['q'], $request->user()->id);

        return response()->json(['drivers' => $results]);
    }
}
