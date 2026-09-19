<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\VanTrip;
use App\Models\VanTripReservation;
use App\Services\VanTrip\VanTripManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reserva de asientos de un viaje VAN desde la app móvil (roadmap Hito 5B,
 * paridad con la web). Reusa App\Services\VanTrip\VanTripManager, la misma
 * lógica que VanTripReservationController (web).
 */
class VanTripReservationController extends Controller
{
    public function __construct(private readonly VanTripManager $vanTrips) {}

    public function store(Request $request, VanTrip $vanTrip): JsonResponse
    {
        $validated = $request->validate(['seats_reserved' => ['required', 'integer', 'min:1', 'max:60']]);

        $this->vanTrips->reserveSeats($vanTrip, $request->user(), $validated['seats_reserved']);

        return response()->json(['message' => 'Reserva confirmada.'], 201);
    }

    public function cancel(Request $request, VanTripReservation $reservation): JsonResponse
    {
        $this->vanTrips->cancelReservation($reservation, $request->user());

        return response()->json(['message' => 'Reserva cancelada.']);
    }
}
