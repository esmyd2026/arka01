<?php

namespace App\Services\Express;

use App\Models\ExpressApplication;
use App\Models\ExpressRoute;
use App\Models\User;
use App\Notifications\ExpressApplicationResultPushNotification;
use App\Notifications\ExpressCompanionApprovalPushNotification;
use App\Services\PlanLimits;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Postulaciones de conductores a una ruta Expreso — extraído de
 * ExpressApplicationController (roadmap app móvil, "full backend").
 */
class ExpressApplicationResponder
{
    public function __construct(private readonly PlanLimits $planLimits) {}

    /**
     * El conductor se postula a una ruta abierta, aceptando el precio
     * ofrecido tal cual o proponiendo otro monto.
     */
    public function apply(ExpressRoute $route, User $driver, ?float $proposedPrice): ExpressApplication
    {
        if (! $this->planLimits->forDriver($driver)['express_enabled']) {
            throw ValidationException::withMessages([
                'route' => 'Su plan actual no incluye postularse a Expresos — hace falta un plan superior.',
            ]);
        }

        if (! $route->isOpenForApplications()) {
            throw ValidationException::withMessages([
                'route' => 'Este Expreso ya no está abierto a postulaciones.',
            ]);
        }

        $alreadyApplied = $route->applications()
            ->where('driver_user_id', $driver->id)
            ->where('status', 'pending')
            ->exists();

        if ($alreadyApplied) {
            throw ValidationException::withMessages([
                'route' => 'Ya se postuló a este Expreso y todavía no le respondieron.',
            ]);
        }

        return ExpressApplication::query()->create([
            'express_route_id' => $route->id,
            'driver_user_id' => $driver->id,
            'proposed_price' => $proposedPrice,
            'status' => 'pending',
            'applied_at' => now(),
        ]);
    }

    /**
     * El cliente acepta una postulación: la ruta pasa a "activa" con este
     * conductor asignado, y las demás postulaciones pendientes se rechazan
     * automáticamente (solo puede haber un conductor asignado a la vez).
     */
    public function accept(ExpressApplication $application): void
    {
        $route = $application->route;

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
    }

    public function reject(ExpressApplication $application): void
    {
        if ($application->status !== 'pending') {
            throw ValidationException::withMessages([
                'application' => 'Esta postulación ya no está pendiente.',
            ]);
        }

        $application->update(['status' => 'rejected', 'responded_at' => now()]);
    }

    /**
     * El conductor retira su propia postulación mientras sigue pendiente.
     */
    public function withdraw(ExpressApplication $application, User $actingUser): void
    {
        if ($application->driver_user_id !== $actingUser->id) {
            abort(403);
        }

        if ($application->status !== 'pending') {
            throw ValidationException::withMessages([
                'application' => 'Esta postulación ya no está pendiente.',
            ]);
        }

        $application->update(['status' => 'withdrawn', 'responded_at' => now()]);
    }
}
