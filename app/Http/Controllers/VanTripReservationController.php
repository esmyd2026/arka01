<?php

namespace App\Http\Controllers;

use App\Models\VanTrip;
use App\Models\VanTripReservation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reserva de asientos de un viaje VAN (pedido explícito del usuario): el
 * cliente explora y reserva directo, sin negociación — el precio por asiento
 * ya lo fijó el conductor al publicar.
 */
class VanTripReservationController extends Controller
{
    public function store(Request $request, VanTrip $vanTrip): RedirectResponse
    {
        if ($vanTrip->driver_user_id === $request->user()->id) {
            abort(403);
        }

        $validated = $request->validate([
            'seats_reserved' => ['required', 'integer', 'min:1', 'max:60'],
        ]);

        DB::transaction(function () use ($vanTrip, $request, $validated) {
            // Bloqueamos la fila para que dos reservas simultáneas no se
            // pasen del cupo real (mismo criterio que RideRequestController::accept()).
            $locked = VanTrip::query()->lockForUpdate()->findOrFail($vanTrip->id);

            if (! $locked->isOpenForBooking()) {
                throw ValidationException::withMessages([
                    'trip' => 'Este viaje ya no está abierto a reservas.',
                ]);
            }

            $alreadyReserved = $locked->reservations()
                ->where('client_user_id', $request->user()->id)
                ->where('status', 'confirmed')
                ->exists();

            if ($alreadyReserved) {
                throw ValidationException::withMessages([
                    'trip' => 'Ya tiene una reserva en este viaje — cancélela si quiere cambiar la cantidad de asientos.',
                ]);
            }

            if ($validated['seats_reserved'] > $locked->seatsAvailable()) {
                throw ValidationException::withMessages([
                    'seats_reserved' => "Solo quedan {$locked->seatsAvailable()} asiento(s) disponible(s).",
                ]);
            }

            VanTripReservation::query()->create([
                'van_trip_id' => $locked->id,
                'client_user_id' => $request->user()->id,
                'seats_reserved' => $validated['seats_reserved'],
                'status' => 'confirmed',
                'reserved_at' => now(),
            ]);
        });

        return back()->with('status', 'Reserva confirmada.');
    }

    /**
     * El propio cliente cancela su reserva, liberando el cupo.
     */
    public function cancel(Request $request, VanTripReservation $reservation): RedirectResponse
    {
        if ($reservation->client_user_id !== $request->user()->id) {
            abort(403);
        }

        $reservation->update(['status' => 'cancelled']);

        return back()->with('status', 'Reserva cancelada.');
    }
}
