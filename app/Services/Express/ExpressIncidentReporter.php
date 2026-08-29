<?php

namespace App\Services\Express;

use App\Models\ExpressIncident;
use App\Models\ExpressRoute;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Reporte de incumplimiento de una condición pactada en un Expreso —
 * extraído de ExpressIncidentController (roadmap app móvil, "full backend").
 */
class ExpressIncidentReporter
{
    public function report(ExpressRoute $route, User $reporter, array $validated): ExpressIncident
    {
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

        return $route->incidents()->create([
            'ride_id' => $validated['ride_id'],
            'express_condition_id' => $validated['express_condition_id'] ?? null,
            'reported_by' => $reporter->id,
            'description' => $validated['description'],
        ]);
    }
}
