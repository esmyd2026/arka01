<?php

namespace App\Http\Controllers;

use App\Models\ExpressRoute;
use App\Services\Express\ExpressIncidentReporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Reporte de incumplimiento de una condición pactada en un Expreso (sección
 * 4.3): el cliente lo reporta desde la carrera puntual del día en que pasó.
 * Queda en el historial del conductor y del Expreso, visible para el
 * administrador ante una disputa (sección 9.6).
 *
 * Lógica real en App\Services\Express\ExpressIncidentReporter (roadmap app
 * móvil, "full backend").
 */
class ExpressIncidentController extends Controller
{
    public function __construct(private readonly ExpressIncidentReporter $incidentReporter) {}

    public function store(Request $request, ExpressRoute $route): RedirectResponse
    {
        $this->authorize('update', $route);

        $validated = $request->validate([
            'ride_id' => ['required', 'integer', 'exists:rides,id'],
            'express_condition_id' => ['nullable', 'integer', 'exists:express_conditions,id'],
            'description' => ['required', 'string', 'max:500'],
        ]);

        $this->incidentReporter->report($route, $request->user(), $validated);

        return back()->with('status', 'Incumplimiento reportado.');
    }
}
