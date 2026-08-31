<?php

namespace App\Http\Controllers;

use App\Models\VanTrip;
use App\Models\VanTripReservation;
use App\Services\VanTrip\VanTripManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Lógica real en App\Services\VanTrip\VanTripManager (roadmap app móvil,
 * "full backend").
 */
class VanTripReservationController extends Controller
{
    public function __construct(private readonly VanTripManager $vanTrips) {}

    public function store(Request $request, VanTrip $vanTrip): RedirectResponse
    {
        $validated = $request->validate(['seats_reserved' => ['required', 'integer', 'min:1', 'max:60']]);

        $this->vanTrips->reserveSeats($vanTrip, $request->user(), $validated['seats_reserved']);

        return back()->with('status', 'Reserva confirmada.');
    }

    public function cancel(Request $request, VanTripReservation $reservation): RedirectResponse
    {
        $this->vanTrips->cancelReservation($reservation, $request->user());

        return back()->with('status', 'Reserva cancelada.');
    }
}
