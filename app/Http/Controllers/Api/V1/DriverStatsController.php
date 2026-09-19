<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Driver\DriverStatsFinder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Mis indicadores" del conductor desde la app móvil (roadmap app móvil,
 * "full backend" — paridad con la web). Reusa
 * App\Services\Driver\DriverStatsFinder, la misma lógica que
 * DriverStatsController (web).
 */
class DriverStatsController extends Controller
{
    public function __construct(private readonly DriverStatsFinder $statsFinder) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isDriver(), 403);

        $data = $this->statsFinder->forDriver(
            $request->user(),
            $request->date('from'),
            $request->date('to'),
            $request->string('status')->toString(),
            (int) $request->input('page', 1),
            (int) $request->input('coop_page', 1),
        );

        return response()->json([
            'totals' => $data['totals'],
            'status_breakdown' => $data['statusBreakdown'],
            'daily_earnings' => $data['dailyEarnings'],
            'gamification' => $data['gamification'],
            'cooperative_wallet' => $data['cooperativeWallet'],
            'cooperative_ride_history' => $data['cooperativeRideHistory']?->items(),
            'cooperative_ride_history_current_page' => $data['cooperativeRideHistory']?->currentPage(),
            'cooperative_ride_history_last_page' => $data['cooperativeRideHistory']?->lastPage(),
            'history' => $data['history']->items(),
            'current_page' => $data['history']->currentPage(),
            'last_page' => $data['history']->lastPage(),
        ]);
    }
}
