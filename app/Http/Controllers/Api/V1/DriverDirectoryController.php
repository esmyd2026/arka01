<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Driver\DriverDirectoryFinder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Directorio de conductores públicos desde la app móvil (roadmap app
 * móvil, "full backend" — paridad con la web). Reusa
 * App\Services\Driver\DriverDirectoryFinder, la misma lógica que
 * DriverDirectoryController (web).
 */
class DriverDirectoryController extends Controller
{
    public function __construct(private readonly DriverDirectoryFinder $directoryFinder) {}

    public function index(Request $request): JsonResponse
    {
        if ($request->user()->isDriver()) {
            return response()->json([
                'message' => 'Los conductores no tienen un directorio propio para buscar.',
            ], 403);
        }

        $data = $this->directoryFinder->browse(
            $request->user(),
            $request->float('lat') ?: null,
            $request->float('lng') ?: null,
            (int) $request->input('page', 1),
            $request->filled('sector_id') ? (int) $request->input('sector_id') : null,
        );

        return response()->json([
            'drivers' => $data['drivers']->items(),
            'current_page' => $data['drivers']->currentPage(),
            'last_page' => $data['drivers']->lastPage(),
            'target_fleet_id' => $data['targetFleetId'],
        ]);
    }
}
