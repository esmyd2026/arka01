<?php

namespace App\Http\Controllers;

use App\Models\ExpressApplication;
use App\Models\ExpressRoute;
use App\Notifications\ExpressApplicationResultPushNotification;
use App\Notifications\ExpressCompanionApprovalPushNotification;
use App\Services\PlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Postulaciones de conductores a una ruta Expreso (sección 4.2). El cliente
 * elige una postulación, o negocia el precio propuesto; acá se simplifica a
 * una sola contraoferta del conductor (proposed_price), sin rondas
 * múltiples, mismo criterio que la negociación de precio de una carrera
 * normal (sección 5).
 */
class ExpressApplicationController extends Controller
{
    public function __construct(private readonly PlanLimits $planLimits) {}

    /**
     * El conductor se postula a una ruta abierta, aceptando el precio
     * ofrecido tal cual o proponiendo otro monto.
     */
    public function store(Request $request, ExpressRoute $route): RedirectResponse
    {
        // Pedido explícito del usuario: módulo de Expresos habilitable por
        // plan de conductor (App\Services\PlanLimits, mismo criterio que
        // VanTripController::store()) — se valida acá, en el momento real de
        // postularse, no solo al mostrar la lista.
        if (! $this->planLimits->forDriver($request->user())['express_enabled']) {
            throw ValidationException::withMessages([
                'route' => 'Su plan actual no incluye postularse a Expresos — hace falta un plan superior.',
            ]);
        }

        if (! $route->isOpenForApplications()) {
            throw ValidationException::withMessages([
                'route' => 'Este Expreso ya no está abierto a postulaciones.',
            ]);
        }

        $validated = $request->validate([
            'proposed_price' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $alreadyApplied = $route->applications()
            ->where('driver_user_id', $request->user()->id)
            ->where('status', 'pending')
            ->exists();

        if ($alreadyApplied) {
            throw ValidationException::withMessages([
                'route' => 'Ya se postuló a este Expreso y todavía no le respondieron.',
            ]);
        }

        ExpressApplication::query()->create([
            'express_route_id' => $route->id,
            'driver_user_id' => $request->user()->id,
            'proposed_price' => $validated['proposed_price'] ?? null,
            'status' => 'pending',
            'applied_at' => now(),
        ]);

        return back()->with('status', 'Postulación enviada.');
    }

    /**
     * El cliente acepta una postulación: la ruta pasa a "activa" con este
     * conductor asignado, y las demás postulaciones pendientes se rechazan
     * automáticamente (solo puede haber un conductor asignado a la vez).
     */
    public function accept(Request $request, ExpressApplication $application): RedirectResponse
    {
        $route = $application->route;
        $this->authorize('update', $route);

        if ($application->status !== 'pending') {
            throw ValidationException::withMessages([
                'application' => 'Esta postulación ya no está pendiente.',
            ]);
        }

        $rejectedApplications = $route->applications()
            ->where('id', '!=', $application->id)
            ->where('status', 'pending')
            ->with('driver')
            ->get();

        DB::transaction(function () use ($application, $route) {
            $application->update(['status' => 'accepted', 'responded_at' => now()]);

            $route->applications()
                ->where('id', '!=', $application->id)
                ->where('status', 'pending')
                ->update(['status' => 'rejected', 'responded_at' => now()]);

            $route->update([
                'status' => 'active',
                'assigned_driver_user_id' => $application->driver_user_id,
                'assigned_at' => now(),
            ]);

            // Quienes ya habían sido aceptados por el dueño necesitan ahora
            // la confirmación operativa del conductor recién asignado.
            $route->companions()
                ->where('status', 'accepted')
                ->update(['driver_approval_status' => 'pending']);
        });

        $application->driver->notify(new ExpressApplicationResultPushNotification($application, true));
        foreach ($rejectedApplications as $rejectedApplication) {
            $rejectedApplication->driver->notify(new ExpressApplicationResultPushNotification($rejectedApplication, false));
        }

        $route->companions()
            ->where('status', 'accepted')
            ->where('driver_approval_status', 'pending')
            ->with('passenger')
            ->get()
            ->each(fn ($companion) => $application->driver->notify(new ExpressCompanionApprovalPushNotification($companion, 'review')));

        return back()->with('status', 'Postulación aceptada. El Expreso ya está activo.');
    }

    /**
     * El cliente rechaza una postulación puntual, sin afectar a las demás.
     */
    public function reject(Request $request, ExpressApplication $application): RedirectResponse
    {
        $this->authorize('update', $application->route);

        if ($application->status !== 'pending') {
            throw ValidationException::withMessages([
                'application' => 'Esta postulación ya no está pendiente.',
            ]);
        }

        $application->update(['status' => 'rejected', 'responded_at' => now()]);

        return back()->with('status', 'Postulación rechazada.');
    }

    /**
     * El conductor retira su propia postulación mientras sigue pendiente.
     */
    public function withdraw(Request $request, ExpressApplication $application): RedirectResponse
    {
        if ($application->driver_user_id !== $request->user()->id) {
            abort(403);
        }

        if ($application->status !== 'pending') {
            throw ValidationException::withMessages([
                'application' => 'Esta postulación ya no está pendiente.',
            ]);
        }

        $application->update(['status' => 'withdrawn', 'responded_at' => now()]);

        return back()->with('status', 'Postulación retirada.');
    }
}
