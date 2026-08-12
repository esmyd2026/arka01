<?php

namespace App\Http\Controllers;

use App\Models\DriverProfile;
use App\Models\DriverTier;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\Review;
use App\Models\Ride;
use App\Services\PlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
     * sección 7.3). En el plan Gratis es una sola, pero el plan Multi-flota
     * permite tener varias — de ahí que esta pantalla sea un listado y no
     * vaya directo al detalle.
     */
    public function index(Request $request): Response|RedirectResponse
    {
        if ($request->user()->isDriver()) {
            return redirect()->route('dashboard')->with('status', self::SINGLE_ROLE_MESSAGE);
        }

        $limits = $this->planLimits->forClient($request->user());

        $fleets = Fleet::query()
            ->where('owner_user_id', $request->user()->id)
            ->withCount('activeMembers')
            ->orderBy('id')
            ->get();

        // Compatibilidad con el flujo de antes de la Fase 5: si el cliente
        // todavía no tiene ninguna flota, se la creamos automáticamente para
        // no pedirle un paso extra de "crear flota" la primera vez.
        if ($fleets->isEmpty()) {
            $fleet = Fleet::query()->create([
                'owner_user_id' => $request->user()->id,
                'name' => 'Mi flota',
            ]);
            $fleet->loadCount('activeMembers');
            $fleets = collect([$fleet]);
        }

        return Inertia::render('Fleet/List', [
            'fleets' => $fleets,
            // null = sin límite de flotas.
            'maxFleets' => $limits['max_fleets'],
            'planCode' => $limits['plan_code'],
            'planName' => $limits['plan_name'],
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
     * pendientes y el buscador para invitar a más (sección 3.2).
     */
    public function show(Request $request, Fleet $fleet): Response
    {
        $this->authorize('view', $fleet);

        $fleet->load([
            'activeMembers.driver.driverProfile',
            'invitations' => fn ($query) => $query->where('status', 'pending')->with('driver'),
        ]);

        $limits = $this->planLimits->forClient($request->user());

        // Pedido explícito del usuario: el roster "Conductores en tu flota"
        // mostraba mucho menos que el buscador de arriba (solo teléfono y
        // tarifa) — mismos datos acá (calificación, cantidad de carreras,
        // categoría), mismo cálculo que searchDrivers().
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

        return Inertia::render('Fleet/Show', [
            'fleet' => $fleet,
            // null = sin límite de conductores por flota.
            'maxDriversPerFleet' => $limits['max_drivers_per_fleet'],
            'memberStats' => $memberStats,
        ]);
    }

    /**
     * Búsqueda tipo red social de conductores para invitar a esta flota
     * puntual (sección 3.2): por nombre, teléfono, código de invitación del
     * conductor, o el usuario/código de socio de la persona (consideración
     * agregada al alcance — buscable para agregar a alguien a la flota).
     * Devuelve JSON porque se consume desde un buscador con resultados en vivo.
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
                $query->whereHas('user', function ($query) use ($term, $memberCode) {
                    $query->where('name', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%")
                        ->orWhere('username', Str::lower($term))
                        ->when($memberCode, fn ($query) => $query->orWhere('member_code', $memberCode));
                })->orWhere('invite_code', strtoupper($term));
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
                'phone' => $driver->user->phone,
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
