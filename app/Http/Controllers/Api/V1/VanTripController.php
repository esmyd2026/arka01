<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\VanTrip;
use App\Services\VanTrip\VanTripManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Viajes tipo VAN/buseta desde la app móvil (roadmap Hito 5B, paridad con
 * la web). Reusa App\Services\VanTrip\VanTripManager, la misma lógica que
 * VanTripController (web).
 */
class VanTripController extends Controller
{
    public function __construct(private readonly VanTripManager $vanTrips) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->vanTrips->forDriver($request->user()));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(VanTripManager::storeRules());

        $trip = $this->vanTrips->store($request->user(), $validated, $request->file('photos', []));

        return response()->json(['trip' => $trip], 201);
    }

    public function show(Request $request, VanTrip $vanTrip): JsonResponse
    {
        return response()->json($this->vanTrips->showFor($vanTrip, $request->user()));
    }

    public function browse(Request $request): JsonResponse
    {
        $data = $this->vanTrips->browse($request->user(), $request->only(['origin_city_id', 'destination_city_id', 'travel_date']));

        return response()->json($data);
    }

    public function cancel(Request $request, VanTrip $vanTrip): JsonResponse
    {
        $this->vanTrips->cancelTrip($vanTrip, $request->user());

        return response()->json(['message' => 'Viaje cancelado.']);
    }
}
