<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ExpressRouteResource;
use App\Models\ExpressRoute;
use App\Services\Express\ExpressRouteFinder;
use App\Services\Express\ExpressRoutePublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Expresos" desde la app móvil (ROADMAP_APLICACION_MOVIL_CAPACITOR.md,
 * "full backend"). Reusa exactamente la misma lógica que la web
 * (App\Services\Express\ExpressRoutePublisher/ExpressRouteFinder, extraídos
 * de ExpressRouteController) — mismas reglas de piso de precio, mismo
 * alcance de "disponibles para mí".
 */
class ExpressRouteController extends Controller
{
    public function __construct(
        private readonly ExpressRouteFinder $expressRouteFinder,
        private readonly ExpressRoutePublisher $expressRoutePublisher,
    ) {}

    /**
     * "Mis Expresos" (lado cliente).
     */
    public function mine(Request $request): JsonResponse
    {
        if ($request->user()->isDriver()) {
            abort(403);
        }

        $routes = $this->expressRouteFinder->mine($request->user());

        return response()->json([
            'routes' => ExpressRouteResource::collection($routes),
            'reference_rate_per_km' => $this->expressRoutePublisher->referenceRatePerKm($request->user()->id),
            'minimum_fare' => $this->expressRoutePublisher->minimumFare(),
            'minimum_price_factor' => ExpressRoutePublisher::MINIMUM_PRICE_FACTOR,
        ]);
    }

    /**
     * Ofertas de Expreso abiertas para un conductor, más los que ya tiene
     * asignados (activos o pausados).
     */
    public function available(Request $request): JsonResponse
    {
        $data = $this->expressRouteFinder->availableForDriver($request->user());

        return response()->json([
            'routes' => ExpressRouteResource::collection($data['routes']),
            'my_applications' => $data['myApplications'],
            'assigned_routes' => ExpressRouteResource::collection($data['assignedRoutes']),
            'my_fleet_count' => $data['myFleetCount'],
            'can_apply' => $data['canApply'],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(ExpressRoutePublisher::rules());

        $route = $this->expressRoutePublisher->create($request->user(), $validated);

        return response()->json(['route' => new ExpressRouteResource($route)], 201);
    }

    public function show(Request $request, ExpressRoute $route): JsonResponse
    {
        $this->authorize('view', $route);

        return response()->json(['route' => new ExpressRouteResource($this->expressRouteFinder->detail($route))]);
    }

    public function update(Request $request, ExpressRoute $route): JsonResponse
    {
        $this->authorize('update', $route);

        $validated = $request->validate(ExpressRoutePublisher::updateRules());

        $this->expressRoutePublisher->update($route, $validated);

        return response()->json(['route' => new ExpressRouteResource($route->fresh())]);
    }

    public function pause(Request $request, ExpressRoute $route): JsonResponse
    {
        $this->authorize('update', $route);

        $this->expressRoutePublisher->pause($route);

        return response()->json(['route' => new ExpressRouteResource($route->fresh())]);
    }

    public function resume(Request $request, ExpressRoute $route): JsonResponse
    {
        $this->authorize('update', $route);

        $this->expressRoutePublisher->resume($route);

        return response()->json(['route' => new ExpressRouteResource($route->fresh())]);
    }

    public function cancel(Request $request, ExpressRoute $route): JsonResponse
    {
        $this->authorize('update', $route);

        $this->expressRoutePublisher->cancel($route);

        return response()->json(['route' => new ExpressRouteResource($route->fresh())]);
    }
}
