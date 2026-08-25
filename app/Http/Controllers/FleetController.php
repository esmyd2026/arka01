<?php

namespace App\Http\Controllers;

use App\Models\ClientCooperative;
use App\Models\Cooperative;
use App\Models\DriverProfile;
use App\Models\DriverTier;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\Review;
use App\Models\Ride;
use App\Services\PlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class FleetController extends Controller
{
    /** Cada cuenta es cliente o conductor, nunca las dos (sección 3.1). */
    private const SINGLE_ROLE_MESSAGE = 'Los conductores no pueden tener una flota propia — cada cuenta es cliente o conductor, no ambas.';

    public function __construct(private readonly PlanLimits $planLimits) {}

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
            'fleets' => $fleets->map(fn (Fleet $fleet) => $this->fleetDetails($fleet)),
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
                ->get(['id', 'name', 'logo_path', 'main_address', 'stand_lat', 'stand_lng'])
                ->map(fn (Cooperative $cooperative) => [
                    'id' => $cooperative->id,
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
        $details = $this->fleetDetails($fleet);

        return Inertia::render('Fleet/Show', [
            'fleet' => $details['fleet'],
            // null = sin límite de conductores por flota.
            'maxDriversPerFleet' => $limits['max_drivers_per_fleet'],
            'memberStats' => $details['memberStats'],
        ]);
    }

    /**
     * Roster completo de una flota (conductores activos, invitaciones
     * pendientes, y las mismas estadísticas por conductor que ya calculaba
     * searchDrivers() — calificación, carreras completadas, categoría,
     * clientes activos). Compartido entre index() (una vez por cada flota
     * del cliente) y show() (respaldo de una sola), para no calcular esto
     * dos veces.
     *
     * @return array{fleet: Fleet, memberStats: Collection}
     */
    private function fleetDetails(Fleet $fleet): array
    {
        $fleet->load([
            'activeMembers.driver.driverProfile',
            // 'inviter' además de 'driver' (pedido explícito del usuario,
            // "Recomendar mi flota"): en una recomendación quien invitó no es
            // el dueño de la flota, así que la pantalla necesita mostrar
            // quién la mandó de verdad (ver FleetRoster.vue).
            'invitations' => fn ($query) => $query->where('status', 'pending')->with(['driver', 'inviter']),
        ]);

        $driverIds = $fleet->activeMembers->pluck('driver_user_id');

        $ratings = Review::query()
            ->whereIn('reviewee_user_id', $driverIds)
            ->selectRaw('reviewee_user_id, avg(rating) as avg_rating, count(*) as review_count')
            ->groupBy('reviewee_user_id')
            ->get()
            ->keyBy('reviewee_user_id');

        $rideCounts = Ride::query()
            ->whereIn('driver_user_id', $driverIds)
            ->where('status', 'completed')
            ->selectRaw('driver_user_id, count(*) as rides_count')
            ->groupBy('driver_user_id')
            ->pluck('rides_count', 'driver_user_id');

        // Pedido explícito del usuario: "cuando busque O TENGA un
        // conductor" — también en el roster de mi flota, no solo al
        // buscarlo. En un solo query agrupado, no uno por conductor
        // (FleetMember::activeClientCount() sirve para un conductor puntual,
        // acá conviene el conteo por lote).
        $clientCounts = FleetMember::query()
            ->whereIn('driver_user_id', $driverIds)
            ->whereNull('left_at')
            ->selectRaw('driver_user_id, count(*) as clients_count')
            ->groupBy('driver_user_id')
            ->pluck('clients_count', 'driver_user_id');

        // Medalla por puntos (pedido explícito del usuario): reemplaza la
        // insignia por calificación de antes — ver App\Models\DriverTier.
        $points = DriverProfile::query()->whereIn('user_id', $driverIds)->pluck('total_points', 'user_id');

        $memberStats = $driverIds->mapWithKeys(function ($driverId) use ($ratings, $rideCounts, $clientCounts, $points) {
            $rating = $ratings->get($driverId);
            $averageRating = $rating ? round((float) $rating->avg_rating, 1) : null;
            $reviewCount = $rating->review_count ?? 0;

            return [$driverId => [
                'average_rating' => $averageRating,
                'review_count' => $reviewCount,
                'rides_count' => $rideCounts->get($driverId, 0),
                'tier' => DriverTier::forPoints($points->get($driverId, 0))->toBadge(),
                'active_clients_count' => $clientCounts->get($driverId, 0),
            ]];
        });

        return ['fleet' => $fleet, 'memberStats' => $memberStats];
    }

    /**
     * Búsqueda de conductores para invitar a esta flota puntual (sección
     * 3.2), SOLO por código de socio o código de invitación (pedido
     * explícito del usuario, con una captura real de varias personas de
     * apellido "Cedeño" saliendo juntas en una búsqueda por nombre: "por
     * código nada más, porque chocarían con millones de personas" — buscar
     * por nombre/teléfono no da un resultado preciso con una base grande de
     * usuarios, y además exponía el teléfono de desconocidos). Devuelve JSON
     * porque se consume desde un buscador con resultados en vivo.
     */
    public function searchDrivers(Request $request, Fleet $fleet)
    {
        $this->authorize('view', $fleet);

        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $term = $validated['q'];
        $memberCode = ctype_digit($term) ? (int) $term : null;

        $drivers = DriverProfile::query()
            ->with('user')
            // Un cliente no se busca ni se invita a sí mismo, aunque también sea conductor.
            ->where('user_id', '!=', $request->user()->id)
            // Pedido explícito del usuario ("pasarme a cliente"): con el
            // perfil pausado no se lo puede buscar/invitar como conductor —
            // la cuenta está operando como cliente ahora mismo.
            ->whereNull('deactivated_at')
            ->where(function ($query) use ($term, $memberCode) {
                $query->when($memberCode, fn ($query) => $query->whereHas('user', fn ($query) => $query->where('member_code', $memberCode)))
                    ->orWhere('invite_code', strtoupper($term));
            })
            ->limit(10)
            ->get();

        // Le sumamos a cada resultado en qué estado está respecto a esta flota
        // (todavía no invitado, invitación pendiente, o ya es miembro activo),
        // para que la pantalla sepa qué botón mostrar sin otra consulta.
        $activeDriverIds = $fleet->activeMembers()->pluck('driver_user_id');
        $pendingDriverIds = $fleet->invitations()->where('status', 'pending')->pluck('driver_user_id');

        // Foto, puntaje, cantidad de carreras y categoría (pedido explícito
        // del usuario) — antes el buscador solo mostraba nombre/teléfono/tarifa,
        // sin nada que ayude a decidir a quién invitar entre varios resultados.
        $driverIds = $drivers->pluck('user_id');

        $ratings = Review::query()
            ->whereIn('reviewee_user_id', $driverIds)
            ->selectRaw('reviewee_user_id, avg(rating) as avg_rating, count(*) as review_count')
            ->groupBy('reviewee_user_id')
            ->get()
            ->keyBy('reviewee_user_id');

        $rideCounts = Ride::query()
            ->whereIn('driver_user_id', $driverIds)
            ->where('status', 'completed')
            ->selectRaw('driver_user_id, count(*) as rides_count')
            ->groupBy('driver_user_id')
            ->pluck('rides_count', 'driver_user_id');

        $results = $drivers->map(function (DriverProfile $driver) use ($activeDriverIds, $pendingDriverIds, $ratings, $rideCounts) {
            $rating = $ratings->get($driver->user_id);
            $averageRating = $rating ? round((float) $rating->avg_rating, 1) : null;
            $reviewCount = $rating->review_count ?? 0;

            return [
                'user_id' => $driver->user_id,
                'name' => $driver->user->name,
                'avatar_url' => $driver->user->avatar_url,
                // Pedido explícito del usuario ("manejar la privacidad"):
                // sin teléfono acá — ya no hace falta como referencia visual
                // ahora que la búsqueda es por código exacto, no por nombre.
                'username' => $driver->user->username,
                'member_code' => $driver->user->member_code,
                'invite_code' => $driver->invite_code,
                'rate_per_km' => $driver->rate_per_km,
                'average_rating' => $averageRating,
                'review_count' => $reviewCount,
                'tier' => DriverTier::forPoints($driver->total_points)->toBadge(),
                'rides_count' => $rideCounts->get($driver->user_id, 0),
                // Pedido explícito del usuario: "saber cuántos clientes
                // tiene ese conductor" al buscarlo — para decidir si sumarse
                // a alguien que ya está muy repartido entre varias flotas.
                'active_clients_count' => $driver->activeClientCount(),
                'status' => match (true) {
                    $activeDriverIds->contains($driver->user_id) => 'member',
                    $pendingDriverIds->contains($driver->user_id) => 'pending',
                    default => 'not_invited',
                },
            ];
        });

        return response()->json(['drivers' => $results->values()]);
    }
}
