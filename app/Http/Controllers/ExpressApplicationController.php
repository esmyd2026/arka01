<?php

namespace App\Http\Controllers;

use App\Models\ExpressApplication;
use App\Models\ExpressRoute;
use App\Services\Express\ExpressApplicationResponder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Postulaciones de conductores a una ruta Expreso (sección 4.2). El cliente
 * elige una postulación, o negocia el precio propuesto; acá se simplifica a
 * una sola contraoferta del conductor (proposed_price), sin rondas
 * múltiples, mismo criterio que la negociación de precio de una carrera
 * normal (sección 5).
 *
 * Lógica real en App\Services\Express\ExpressApplicationResponder (roadmap
 * app móvil, "full backend").
 */
class ExpressApplicationController extends Controller
{
    public function __construct(private readonly ExpressApplicationResponder $expressApplicationResponder) {}

    /**
     * El conductor se postula a una ruta abierta, aceptando el precio
     * ofrecido tal cual o proponiendo otro monto.
     */
    public function store(Request $request, ExpressRoute $route): RedirectResponse
    {
        $validated = $request->validate([
            'proposed_price' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $this->expressApplicationResponder->apply($route, $request->user(), $validated['proposed_price'] ?? null);

        return back()->with('status', 'Postulación enviada.');
    }

    /**
     * El cliente acepta una postulación: la ruta pasa a "activa" con este
     * conductor asignado, y las demás postulaciones pendientes se rechazan
     * automáticamente (solo puede haber un conductor asignado a la vez).
     */
    public function accept(Request $request, ExpressApplication $application): RedirectResponse
    {
        $this->authorize('update', $application->route);

        $this->expressApplicationResponder->accept($application);

        return back()->with('status', 'Postulación aceptada. El Expreso ya está activo.');
    }

    /**
     * El cliente rechaza una postulación puntual, sin afectar a las demás.
     */
    public function reject(Request $request, ExpressApplication $application): RedirectResponse
    {
        $this->authorize('update', $application->route);

        $this->expressApplicationResponder->reject($application);

        return back()->with('status', 'Postulación rechazada.');
    }

    /**
     * El conductor retira su propia postulación mientras sigue pendiente.
     */
    public function withdraw(Request $request, ExpressApplication $application): RedirectResponse
    {
        $this->expressApplicationResponder->withdraw($application, $request->user());

        return back()->with('status', 'Postulación retirada.');
    }
}
