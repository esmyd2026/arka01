<?php

namespace App\Http\Controllers;

use App\Models\DriverProfile;
use App\Models\DriverTier;
use App\Models\Fleet;
use App\Models\Review;
use App\Services\Haversine;
use App\Services\PlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

class DriverDirectoryController extends Controller
{
    /** Pedido explícito del usuario: "buscar conductores para mi flota" es del lado cliente. */
    private const SINGLE_ROLE_MESSAGE = 'Los conductores no tienen un directorio propio para buscar — cada cuenta es cliente o conductor, no ambas.';

    public function __construct(private readonly PlanLimits $planLimits) {}

    /**
     * Directorio de conductores públicos (sección 3.4): la red de respaldo
     * cuando nadie de la flota personal está disponible. Ordena por cercanía
     * si el navegador comparte ubicación, si no por mejor calificados.
     *
     * A esta escala (el piloto contempla ~100 conductores activos, sección
     * 11.2) alcanza con traer todos los públicos a memoria y ordenar ahí; si
     * el volumen crece, la sección 9.7 ya deja anotado cachear esto en Redis.
     */
    public function index(Request $request): Response|RedirectResponse
    {
        // Bug reportado por el usuario (caso real: un conductor terminó con
        // una flota propia fantasma, y su perfil público le mostraba la
        // insignia de "Cliente" por eso) — mismo criterio que
        // RideRequestController::create(): sin este guard, un conductor que
        // entrara por URL directa terminaba provisionándose una flota propia
        // solo por pisar esta pantalla (el bloque de abajo la crea sola si
        // no existe, para armar el botón "Invitar").
        if ($request->user()->isDriver()) {
            return redirect()->route('dashboard')->with('status', self::SINGLE_ROLE_MESSAGE);
        }

        $lat = $request->float('lat') ?: null;
        $lng = $request->float('lng') ?: null;
        $perPage = 12;
        $page = max(1, (int) $request->input('page', 1));

        $driverProfiles = DriverProfile::query()
            ->where('is_public', true)
            ->where('verification_status', '!=', 'rejected')
            // Panel admin (pedido explícito del usuario): un conductor
            // suspendido no aparece ni para descubrirlo de nuevas.
            ->whereNull('suspended_at')
            ->with('user')
            ->get();

        $userIds = $driverProfiles->pluck('user_id');

        // Para saber si cada conductor ya está invitado/es miembro de la flota
        // "principal" del cliente (mismo criterio que FleetController::searchDrivers)
        // y así el botón "Invitar" sepa qué mostrar sin otra vuelta al servidor.
        // Con multi-flota (sección 7.3) invitar desde el directorio siempre va a
        // la primera flota; para invitar a otra, el cliente usa esa flota puntual.
        $fleet = Fleet::query()
            ->where('owner_user_id', $request->user()->id)
            ->orderBy('id')
            ->first();

        if (! $fleet) {
            $fleet = Fleet::query()->create([
                'owner_user_id' => $request->user()->id,
                'name' => 'Mi flota',
            ]);
        }

        $activeDriverIds = $fleet->activeMembers()->pluck('driver_user_id');
        $pendingDriverIds = $fleet->invitations()->where('status', 'pending')->pluck('driver_user_id');

        $ratings = Review::query()
            ->whereIn('reviewee_user_id', $userIds)
            ->selectRaw('reviewee_user_id, avg(rating) as avg_rating, count(*) as review_count')
            ->groupBy('reviewee_user_id')
            ->get()
            ->keyBy('reviewee_user_id');

        $entries = $driverProfiles
            // Un cliente no se ve a sí mismo en el directorio aunque también sea conductor.
            ->reject(fn (DriverProfile $profile) => $profile->user_id === $request->user()->id)
            ->map(function (DriverProfile $profile) use ($ratings, $lat, $lng, $activeDriverIds, $pendingDriverIds) {
                $rating = $ratings->get($profile->user_id);

                $distanceKm = ($lat !== null && $lng !== null && $profile->current_lat !== null)
                    ? Haversine::distanceKm($lat, $lng, (float) $profile->current_lat, (float) $profile->current_lng)
                    : null;

                return [
                    'user_id' => $profile->user_id,
                    'name' => $profile->user->name,
                    'avatar_url' => $profile->user->avatar_url,
                    'rate_per_km' => $profile->rate_per_km,
                    'accepts_cash' => $profile->accepts_cash,
                    'accepts_transfer' => $profile->accepts_transfer,
                    // Bug reportado por el usuario: sin ping reciente de
                    // ubicación, `is_available` puede seguir en true hasta
                    // que corra el barrido de 2 minutos — salvo que siga
                    // alcanzable por WhatsApp (DriverProfile::isReachable()).
                    'is_available' => $profile->is_available && $profile->isReachable($profile->user->hasActiveWhatsAppSession()),
                    'average_rating' => $rating ? round((float) $rating->avg_rating, 1) : null,
                    'review_count' => $rating->review_count ?? 0,
                    'distance_km' => $distanceKm,
                    // Verificación visible (sección 8): insignia + miniatura del
                    // vehículo, para reforzar confianza con un conductor desconocido.
                    // La insignia depende del plan vigente además de la aprobación
                    // del admin (sección 7.2) — antes de este fix se mostraba igual
                    // sin importar el plan.
                    'is_verified' => $profile->verification_status === 'approved'
                        && $this->planLimits->forDriver($profile->user)['verified_badge'],
                    'vehicle_photo_url' => $profile->vehicle_photo_url,
                    // Medalla por puntos (pedido explícito del usuario): se
                    // usa abajo para filtrar (solo Oro para arriba entra al
                    // directorio, además del plan que ya se filtró en el
                    // query) y para ordenar (Diamante antes que Oro).
                    'tier' => DriverTier::forPoints($profile->total_points)->toBadge(),
                    'status' => match (true) {
                        $activeDriverIds->contains($profile->user_id) => 'member',
                        $pendingDriverIds->contains($profile->user_id) => 'pending',
                        default => 'not_invited',
                    },
                ];
            })
            // Pedido explícito del usuario: además de pagar un plan que
            // habilite el directorio, hay que haber ganado con carreras
            // completadas una medalla marcada como "aparece en público" (hoy
            // Oro y Diamante) — la medalla se gana con actividad, no se compra.
            ->filter(fn ($e) => $e['tier']['is_public_eligible']);

        // Medalla más alta primero (Diamante por encima de Oro, pedido
        // explícito del usuario), y dentro de la misma medalla, cercanía si
        // tenemos ubicación del cliente, si no mejor calificados primero.
        $entries = $entries->sort(function ($a, $b) use ($lat) {
            $byTier = $b['tier']['min_points'] <=> $a['tier']['min_points'];
            if ($byTier !== 0) {
                return $byTier;
            }

            return $lat !== null
                ? ($a['distance_km'] ?? PHP_FLOAT_MAX) <=> ($b['distance_km'] ?? PHP_FLOAT_MAX)
                : ($b['average_rating'] ?? 0) <=> ($a['average_rating'] ?? 0);
        });

        $entries = $entries->values();

        $paginated = new LengthAwarePaginator(
            $entries->forPage($page, $perPage)->values(),
            $entries->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return Inertia::render('Directory/Index', [
            'drivers' => $paginated,
            'targetFleetId' => $fleet->id,
        ]);
    }
}
