<?php

namespace App\Services\Express;

use App\Models\ExpressRoute;
use App\Models\Fleet;
use App\Models\User;
use App\Services\PlanLimits;
use Illuminate\Support\Collection;

/**
 * Consultas de lectura de Expresos (directorio del conductor, "Mis
 * Expresos" del cliente, detalle) — extraído de ExpressRouteController
 * (roadmap app móvil, "full backend").
 */
class ExpressRouteFinder
{
    public function __construct(private readonly PlanLimits $planLimits) {}

    /**
     * "Mis Expresos" (lado cliente): los que publicó, con su estado y
     * cantidad de postulaciones pendientes de revisar.
     */
    public function mine(User $client): Collection
    {
        return ExpressRoute::query()
            ->where('client_user_id', $client->id)
            ->withCount(['applications as pending_applications_count' => fn ($query) => $query->where('status', 'pending')])
            ->with('assignedDriver')
            ->latest()
            ->get();
    }

    /**
     * Ofertas de Expreso abiertas para un conductor (sección 4.2): las que
     * publicaron clientes de flotas a las que pertenece, más los Expresos ya
     * asignados a él (activos o pausados) para que tenga un panel
     * persistente después de ser elegido.
     */
    public function availableForDriver(User $driver): array
    {
        $clientIds = Fleet::query()
            ->whereHas('activeMembers', fn ($query) => $query->where('driver_user_id', $driver->id))
            ->pluck('owner_user_id');

        $routes = ExpressRoute::query()
            ->where('status', 'open')
            ->whereIn('client_user_id', $clientIds)
            ->with(['client', 'conditions'])
            ->withCount('applications')
            ->latest()
            ->get();

        $myApplications = $driver->expressApplications()->pluck('status', 'express_route_id');

        $assignedRoutes = ExpressRoute::query()
            ->where('assigned_driver_user_id', $driver->id)
            ->whereIn('status', ['active', 'paused'])
            ->with([
                'client',
                'conditions',
                'companions' => fn ($query) => $query
                    ->where('status', 'accepted')
                    ->with('passenger'),
                'rideRequests' => fn ($query) => $query
                    ->where('scheduled_at', '>=', now()->subDay())
                    ->with(['client', 'ride'])
                    ->orderBy('scheduled_at'),
            ])
            ->orderBy('departure_time')
            ->get()
            ->each(function (ExpressRoute $route) {
                $route->next_run_at = $route->nextRunAt(now())?->toIso8601String();
                $route->pending_companion_approvals_count = $route->companions
                    ->where('driver_approval_status', 'pending')
                    ->count();
            });

        return [
            'routes' => $routes,
            'myApplications' => $myApplications,
            'assignedRoutes' => $assignedRoutes,
            'myFleetCount' => $clientIds->count(),
            'canApply' => $this->planLimits->forDriver($driver)['express_enabled'],
        ];
    }

    /**
     * Detalle de un Expreso: condiciones, postulaciones, conductor asignado
     * e historial de carreras generadas día a día.
     */
    public function detail(ExpressRoute $route): ExpressRoute
    {
        $route->load([
            'conditions',
            'assignedDriver',
            'applications' => fn ($query) => $query->with('driver')->latest(),
            'rideRequests' => fn ($query) => $query->with('ride')->latest(),
            'companions' => fn ($query) => $query->with('passenger')->latest(),
        ]);

        $route->price_per_person = $route->pricePerPerson();

        return $route;
    }
}
