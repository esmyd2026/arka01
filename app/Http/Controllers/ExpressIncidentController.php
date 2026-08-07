<?php

namespace App\Http\Controllers;

use App\Models\ExpressRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Reporte de incumplimiento de una condición pactada en un Expreso (sección
 * 4.3): el cliente lo reporta desde la carrera puntual del día en que pasó.
 * Queda en el historial del conductor y del Expreso, visible para el
 * administrador ante una disputa (sección 9.6).
 */
class ExpressIncidentController extends Controller
{
    public function store(Request $request, ExpressRoute $route): RedirectResponse
    {
        $this->authorize('update', $route);

        $validated = $request->validate([
            'ride_id' => ['required', 'integer', 'exists:rides,id'],
            'express_condition_id' => ['nullable', 'integer', 'exists:express_conditions,id'],
            'description' => ['required', 'string', 'max:500'],
        ]);

        // La carrera reportada tiene que venir de este mismo Expreso — no se
        // puede reportar un incumplimiento sobre una carrera ajena.
        $belongsToRoute = $route->rideRequests()
            ->whereHas('ride', fn ($query) => $query->where('id', $validated['ride_id']))
            ->exists();

        if (! $belongsToRoute) {
            throw ValidationException::withMessages([
                'ride_id' => 'Esa carrera no pertenece a este Expreso.',
            ]);
        }

        if (! empty($validated['express_condition_id'])) {
            $conditionBelongsToRoute = $route->conditions()->where('id', $validated['express_condition_id'])->exists();

            if (! $conditionBelongsToRoute) {
                throw ValidationException::withMessages([
                    'express_condition_id' => 'Esa condición no pertenece a este Expreso.',
                ]);
            }
        }

        $route->incidents()->create([
            'ride_id' => $validated['ride_id'],
            'express_condition_id' => $validated['express_condition_id'] ?? null,
            'reported_by' => $request->user()->id,
            'description' => $validated['description'],
        ]);

        return back()->with('status', 'Incumplimiento reportado.');
    }
}
