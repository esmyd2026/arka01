<?php

namespace App\Http\Controllers;

use App\Models\FleetMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FleetMemberController extends Controller
{
    /**
     * El cliente saca (declina) a un conductor de su flota (sección 3.2 y 3.3).
     * No se borra la fila: se cierra con left_at para conservar el historial
     * de la relación (trazabilidad, sección 9.6).
     */
    public function destroy(Request $request, FleetMember $member): RedirectResponse
    {
        // Solo el dueño de la flota a la que pertenece este miembro puede sacarlo.
        $this->authorize('update', $member->fleet);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $member->update([
            'left_at' => now(),
            'left_reason' => $validated['reason'] ?? null,
            'removed_by' => $request->user()->id,
        ]);

        return back();
    }
}
