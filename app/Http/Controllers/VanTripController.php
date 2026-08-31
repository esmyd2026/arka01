<?php

namespace App\Http\Controllers;

use App\Models\VanTrip;
use App\Services\VanTrip\VanTripManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lógica real en App\Services\VanTrip\VanTripManager (roadmap app móvil,
 * "full backend").
 */
class VanTripController extends Controller
{
    public function __construct(private readonly VanTripManager $vanTrips) {}

    public function index(Request $request): Response
    {
        return Inertia::render('VanTrips/Index', $this->vanTrips->forDriver($request->user()));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(VanTripManager::storeRules());

        $trip = $this->vanTrips->store($request->user(), $validated, $request->file('photos', []));

        return redirect()->route('van-trips.show', $trip);
    }

    public function show(Request $request, VanTrip $vanTrip): Response
    {
        return Inertia::render('VanTrips/Show', $this->vanTrips->showFor($vanTrip, $request->user()));
    }

    public function browse(Request $request): Response
    {
        $data = $this->vanTrips->browse($request->user(), $request->only(['origin_city_id', 'destination_city_id', 'travel_date']));

        return Inertia::render('VanTrips/Browse', [
            ...$data,
            'filters' => $request->only(['origin_city_id', 'destination_city_id', 'travel_date']),
        ]);
    }

    public function cancel(Request $request, VanTrip $vanTrip): RedirectResponse
    {
        $this->vanTrips->cancelTrip($vanTrip, $request->user());

        return back()->with('status', 'Viaje cancelado.');
    }
}
