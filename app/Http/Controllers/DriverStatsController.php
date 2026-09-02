<?php

namespace App\Http\Controllers;

use App\Services\Driver\DriverStatsFinder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Mis indicadores" (pedido explícito del usuario: "el reporte de conductor
 * se siente pobre" — antes era una lista plana de 20 carreras sin filtrar ni
 * paginar, ver Ride/Index.vue): trazabilidad completa con filtros, tarjetas
 * de indicadores, segmentación por estado y por día, y el progreso de
 * medallas — solo lado conductor. Lógica real en
 * App\Services\Driver\DriverStatsFinder (roadmap app móvil, "full backend").
 */
class DriverStatsController extends Controller
{
    private const SINGLE_ROLE_MESSAGE = 'Este reporte es del lado conductor — cada cuenta es cliente o conductor, no ambas.';

    public function __construct(private readonly DriverStatsFinder $statsFinder) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if (! $user->isDriver()) {
            return redirect()->route('dashboard')->with('status', self::SINGLE_ROLE_MESSAGE);
        }

        $data = $this->statsFinder->forDriver(
            $user,
            $request->date('from'),
            $request->date('to'),
            $request->string('status')->toString(),
            (int) $request->input('page', 1),
            (int) $request->input('coop_page', 1),
        );

        return Inertia::render('Driver/Stats', [
            'filters' => $request->only(['from', 'to', 'status']),
            'totals' => $data['totals'],
            'statusBreakdown' => $data['statusBreakdown'],
            'dailyEarnings' => $data['dailyEarnings'],
            'gamification' => $data['gamification'],
            'cooperativeWallet' => $data['cooperativeWallet'],
            'cooperativeRideHistory' => $data['cooperativeRideHistory']?->withQueryString(),
            'history' => $data['history']->withQueryString(),
        ]);
    }
}
