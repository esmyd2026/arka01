<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ExpressRoute;
use App\Services\Express\ExpressIncidentReporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reporte de incumplimiento de una condición pactada en un Expreso, desde
 * la app móvil (roadmap app móvil, "full backend"). Reusa
 * App\Services\Express\ExpressIncidentReporter, la misma lógica que la web.
 */
class ExpressIncidentController extends Controller
{
    public function __construct(private readonly ExpressIncidentReporter $incidentReporter) {}

    public function store(Request $request, ExpressRoute $route): JsonResponse
    {
        $this->authorize('update', $route);

        $validated = $request->validate([
            'ride_id' => ['required', 'integer', 'exists:rides,id'],
            'express_condition_id' => ['nullable', 'integer', 'exists:express_conditions,id'],
            'description' => ['required', 'string', 'max:500'],
        ]);

        $incident = $this->incidentReporter->report($route, $request->user(), $validated);

        return response()->json(['id' => $incident->id], 201);
    }
}
