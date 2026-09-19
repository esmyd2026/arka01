<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ExpressApplicationResource;
use App\Models\ExpressApplication;
use App\Models\ExpressRoute;
use App\Services\Express\ExpressApplicationResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Postulaciones de conductores a un Expreso, desde la app móvil (roadmap
 * app móvil, "full backend"). Reusa App\Services\Express\ExpressApplicationResponder,
 * la misma lógica que la web.
 */
class ExpressApplicationController extends Controller
{
    public function __construct(private readonly ExpressApplicationResponder $expressApplicationResponder) {}

    public function store(Request $request, ExpressRoute $route): JsonResponse
    {
        $validated = $request->validate([
            'proposed_price' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $application = $this->expressApplicationResponder->apply($route, $request->user(), $validated['proposed_price'] ?? null);

        return response()->json(['application' => new ExpressApplicationResource($application)], 201);
    }

    public function accept(Request $request, ExpressApplication $application): JsonResponse
    {
        $this->authorize('update', $application->route);

        $this->expressApplicationResponder->accept($application);

        return response()->json(['application' => new ExpressApplicationResource($application->fresh())]);
    }

    public function reject(Request $request, ExpressApplication $application): JsonResponse
    {
        $this->authorize('update', $application->route);

        $this->expressApplicationResponder->reject($application);

        return response()->json(['application' => new ExpressApplicationResource($application->fresh())]);
    }

    public function withdraw(Request $request, ExpressApplication $application): JsonResponse
    {
        $this->expressApplicationResponder->withdraw($application, $request->user());

        return response()->json(['application' => new ExpressApplicationResource($application->fresh())]);
    }
}
