<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ExpressRouteCompanionResource;
use App\Http\Resources\Api\V1\ExpressRouteResource;
use App\Models\ExpressRoute;
use App\Models\ExpressRouteCompanion;
use App\Services\Express\ExpressRouteCompanionResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Compartir un Expreso con otros clientes de ruta parecida, desde la app
 * móvil (roadmap app móvil, "full backend"). Reusa
 * App\Services\Express\ExpressRouteCompanionResponder, la misma lógica que
 * la web.
 */
class ExpressRouteCompanionController extends Controller
{
    public function __construct(private readonly ExpressRouteCompanionResponder $companionResponder) {}

    public function discover(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'origin_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'origin_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'destination_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'destination_lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $routes = $this->companionResponder->discover(
            $request->user(),
            isset($validated['origin_lat']) ? (float) $validated['origin_lat'] : null,
            isset($validated['origin_lng']) ? (float) $validated['origin_lng'] : null,
            isset($validated['destination_lat']) ? (float) $validated['destination_lat'] : null,
            isset($validated['destination_lng']) ? (float) $validated['destination_lng'] : null,
        );

        return response()->json(['routes' => ExpressRouteResource::collection($routes)]);
    }

    public function store(Request $request, ExpressRoute $route): JsonResponse
    {
        $validated = $request->validate([
            'origin_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'origin_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'origin_address' => ['nullable', 'string', 'max:255'],
            'destination_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'destination_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'destination_address' => ['nullable', 'string', 'max:255'],
        ]);

        $companion = $this->companionResponder->request($route, $request->user(), $validated);

        return response()->json(['companion' => new ExpressRouteCompanionResource($companion)], 201);
    }

    public function accept(Request $request, ExpressRouteCompanion $companion): JsonResponse
    {
        $this->authorize('update', $companion->route);

        $this->companionResponder->accept($companion);

        return response()->json(['companion' => new ExpressRouteCompanionResource($companion->fresh())]);
    }

    public function reject(Request $request, ExpressRouteCompanion $companion): JsonResponse
    {
        $this->authorize('update', $companion->route);

        $this->companionResponder->reject($companion);

        return response()->json(['companion' => new ExpressRouteCompanionResource($companion->fresh())]);
    }

    public function leave(Request $request, ExpressRouteCompanion $companion): JsonResponse
    {
        $this->companionResponder->leave($companion, $request->user());

        return response()->json(['companion' => new ExpressRouteCompanionResource($companion->fresh())]);
    }

    public function driverAccept(Request $request, ExpressRouteCompanion $companion): JsonResponse
    {
        $this->companionResponder->driverAccept($companion, $request->user());

        return response()->json(['companion' => new ExpressRouteCompanionResource($companion->fresh())]);
    }

    public function driverReject(Request $request, ExpressRouteCompanion $companion): JsonResponse
    {
        $this->companionResponder->driverReject($companion, $request->user());

        return response()->json(['companion' => new ExpressRouteCompanionResource($companion->fresh())]);
    }
}
