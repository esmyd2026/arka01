<?php

namespace App\Http\Controllers;

use App\Models\DriverTier;
use App\Models\Ride;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Mis indicadores" (pedido explícito del usuario: "el reporte de conductor
 * se siente pobre" — antes era una lista plana de 20 carreras sin filtrar ni
 * paginar, ver Ride/Index.vue): trazabilidad completa con filtros, tarjetas
 * de indicadores, segmentación por estado y por día, y el progreso de
 * medallas — solo lado conductor.
 */
class DriverStatsController extends Controller
{
    private const SINGLE_ROLE_MESSAGE = 'Este reporte es del lado conductor — cada cuenta es cliente o conductor, no ambas.';

    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if (! $user->isDriver()) {
            return redirect()->route('dashboard')->with('status', self::SINGLE_ROLE_MESSAGE);
        }

        $userId = $user->id;
        $from = $request->date('from');
        $to = $request->date('to');
        $status = $request->string('status')->toString();

        // Closures, no un Builder guardado en variable: cada llamada arma un
        // query nuevo desde `Ride::query()`, así que no hace falta clonar
        // nada para poder reusar los mismos filtros en cada agregado.
        $baseQuery = fn () => Ride::query()
            ->where('driver_user_id', $userId)
            ->when($from, fn ($query) => $query->whereDate('created_at', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('created_at', '<=', $to));

        $filteredQuery = fn () => $baseQuery()->when($status !== '', fn ($query) => $query->where('status', $status));

        $totals = [
            'total' => $filteredQuery()->count(),
            'completed' => $filteredQuery()->where('status', 'completed')->count(),
            'cancelled' => $filteredQuery()->where('status', 'cancelled')->count(),
            'earnings' => (float) $filteredQuery()->where('status', 'completed')->sum('price'),
            'distance_km' => (float) $filteredQuery()->where('status', 'completed')->sum('distance_km'),
        ];

        $totals['average_rating'] = round((float) $user->reviewsReceived()->avg('rating'), 1);
        $totals['review_count'] = $user->reviewsReceived()->count();

        // Torta completadas/canceladas: a propósito SIN el filtro de estado
        // (si ya se filtró por un solo estado, la torta daría una sola
        // porción, sin sentido) — solo respeta el rango de fechas.
        $statusBreakdown = [
            'completed' => $baseQuery()->where('status', 'completed')->count(),
            'cancelled' => $baseQuery()->where('status', 'cancelled')->count(),
        ];

        // Ganancia por día (agrupado en PHP, no con DATE()/GROUP BY de SQL:
        // los tests corren contra SQLite, producción probablemente MySQL —
        // mismo criterio que Admin\OperationsController::demandByHour()).
        // Sin rango elegido, se limita a los últimos 14 días para no traer
        // el historial completo de una carrera muy vieja.
        $earningsQuery = $baseQuery()->where('status', 'completed');
        if (! $from && ! $to) {
            $earningsQuery->where('created_at', '>=', now()->subDays(13)->startOfDay());
        }
        $completedRides = $earningsQuery->get(['created_at', 'price']);

        $earningsByDay = $completedRides
            ->groupBy(fn (Ride $ride) => $ride->created_at->toDateString())
            ->map(fn ($rides) => round((float) $rides->sum('price'), 2))
            ->sortKeys();

        $dailyEarnings = $earningsByDay->map(fn ($total, $date) => [
            'label' => Carbon::parse($date)->isoFormat('D MMM'),
            'value' => $total,
        ])->values();

        // Medallas (pedido explícito del usuario: "su meta con sus puntos y
        // lo que va a lograr cuando recorra tantos puntos la próxima meta")
        // — no depende de los filtros de arriba, es un estado de cuenta.
        $totalPoints = $user->driverProfile->total_points ?? 0;
        $tier = DriverTier::forPoints($totalPoints);
        $nextTier = DriverTier::nextAfter($totalPoints);

        $history = $filteredQuery()
            ->with(['client'])
            ->latest('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Ride $ride) => [
                'id' => $ride->id,
                'date' => $ride->created_at->toIso8601String(),
                'client_name' => $ride->client->name,
                'origin_address' => $ride->origin_address,
                'destination_address' => $ride->destination_address,
                'distance_km' => $ride->distance_km,
                'price' => $ride->price,
                'payment_method' => $ride->payment_method,
                'status' => $ride->status,
                'points_earned' => $ride->points_earned,
            ]);

        return Inertia::render('Driver/Stats', [
            'filters' => $request->only(['from', 'to', 'status']),
            'totals' => $totals,
            'statusBreakdown' => $statusBreakdown,
            'dailyEarnings' => $dailyEarnings,
            'gamification' => [
                'total_points' => $totalPoints,
                'tier' => $tier->toBadge(),
                'next_tier' => $nextTier?->toBadge(),
            ],
            'history' => $history,
        ]);
    }
}
