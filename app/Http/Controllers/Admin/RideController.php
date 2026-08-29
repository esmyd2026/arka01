<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use App\Models\Review;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\RideStop;
use App\Services\AdminAuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pedido explícito del usuario: "que pueda eliminar carrera desde el panel
 * para poder depurar esas de prueba" — cubre carreras sueltas, programadas
 * y las que vienen de un Expreso (todas son filas de `rides`, ver
 * investigación previa: un VanTrip no genera una fila en `rides`, así que
 * queda fuera de este controlador).
 */
class RideController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Ride::query()
            ->with(['client:id,name,phone', 'driver:id,name,phone'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = $request->string('q');
                $query->where(function ($query) use ($term) {
                    $query->whereHas('client', fn ($query) => $query->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"))
                        ->orWhereHas('driver', fn ($query) => $query->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"));
                });
            })
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $query->through(fn (Ride $ride) => [
            'id' => $ride->id,
            'status' => $ride->status,
            'client_name' => $ride->client?->name ?? 'Cuenta eliminada',
            'driver_name' => $ride->driver?->name ?? 'Cuenta eliminada',
            'origin_address' => $ride->origin_address,
            'destination_address' => $ride->destination_address,
            'distance_km' => (float) $ride->distance_km,
            'price' => (float) $ride->price,
            'points_earned' => $ride->points_earned,
            'started_at' => $ride->started_at?->toIso8601String(),
            'completed_at' => $ride->completed_at?->toIso8601String(),
            'cancelled_at' => $ride->cancelled_at?->toIso8601String(),
            'created_at' => $ride->created_at->toIso8601String(),
        ]);

        return Inertia::render('Admin/Rides', [
            'rides' => $query,
            'filters' => $request->only(['q', 'status']),
        ]);
    }

    /**
     * Pedido explícito del usuario: "permiteme también ver el detalle de las
     * carreras cuando le de click alguna de ellas" — toda la información de
     * una carrera puntual (solicitud original, paradas, reseñas, cancelación
     * si la hubo) en una sola pantalla, mismo criterio que
     * Admin\UserProfileController::show().
     */
    public function show(Ride $ride): Response
    {
        $ride->load([
            'client:id,name,phone',
            'driver:id,name,phone',
            'fleet:id,name',
            'rideRequest.cooperative:id,name',
            'stops' => fn ($query) => $query->orderBy('sequence'),
            'stops.sector:id,name',
            'originSector:id,name',
            'destinationSector:id,name',
        ]);

        $reviews = Review::query()
            ->where('ride_id', $ride->id)
            ->with(['reviewer:id,name', 'reviewee:id,name', 'ratingReason:id,text'])
            ->get()
            ->map(fn (Review $review) => [
                'id' => $review->id,
                'reviewer_name' => $review->reviewer?->name ?? 'Cuenta eliminada',
                'reviewee_name' => $review->reviewee?->name ?? 'Cuenta eliminada',
                'rating' => $review->rating,
                'rating_reason' => $review->ratingReason?->text,
                'comment' => $review->comment,
                'created_at' => $review->created_at->toIso8601String(),
            ]);

        $cancelledByLabel = match ($ride->cancelled_by) {
            'client' => 'El cliente',
            'driver' => 'El conductor',
            'admin' => 'Un administrador',
            default => null,
        };

        return Inertia::render('Admin/RideDetail', [
            'ride' => [
                'id' => $ride->id,
                'status' => $ride->status,
                'client' => $ride->client ? ['id' => $ride->client->id, 'name' => $ride->client->name, 'phone' => $ride->client->phone] : null,
                'driver' => $ride->driver ? ['id' => $ride->driver->id, 'name' => $ride->driver->name, 'phone' => $ride->driver->phone] : null,
                'fleet_name' => $ride->fleet?->name,
                'cooperative_name' => $ride->rideRequest?->cooperative?->name,
                'origin_address' => $ride->origin_address,
                'origin_sector' => $ride->originSector?->name,
                'destination_address' => $ride->destination_address,
                'destination_sector' => $ride->destinationSector?->name,
                'distance_km' => (float) $ride->distance_km,
                // Cargo por trayecto de recogida (pedido explícito del
                // usuario: "en el admin también quiero ver eso") — se
                // muestra igual que al conductor, para indicadores y soporte.
                'pickup_distance_km' => $ride->pickup_distance_km !== null ? (float) $ride->pickup_distance_km : null,
                'pickup_fare' => $ride->pickup_fare !== null ? (float) $ride->pickup_fare : null,
                'pickup_fare_charged' => (bool) $ride->pickup_fare_charged,
                'payment_method' => $ride->payment_method,
                'notes' => $ride->notes,
                'round_trip' => $ride->round_trip,
                'rate_per_km_snapshot' => (float) $ride->rate_per_km_snapshot,
                'price' => (float) $ride->price,
                'stops_price' => (float) $ride->stops_price,
                'settled_price' => $ride->settled_price !== null ? (float) $ride->settled_price : null,
                'points_earned' => $ride->points_earned,
                'is_scheduled' => (bool) $ride->rideRequest?->is_scheduled,
                'scheduled_at' => $ride->rideRequest?->scheduled_at?->toIso8601String(),
                'requested_at' => $ride->rideRequest?->requested_at?->toIso8601String(),
                'created_at' => $ride->created_at->toIso8601String(),
                'started_at' => $ride->started_at?->toIso8601String(),
                'heading_to_passenger_at' => $ride->heading_to_passenger_at?->toIso8601String(),
                'arrived_at' => $ride->arrived_at?->toIso8601String(),
                'picked_up_at' => $ride->picked_up_at?->toIso8601String(),
                'completed_at' => $ride->completed_at?->toIso8601String(),
                'completion_reason' => $ride->completion_reason,
                'completion_note' => $ride->completion_note,
                'cancelled_at' => $ride->cancelled_at?->toIso8601String(),
                'cancelled_by_label' => $cancelledByLabel,
                'cancellation_reason' => $ride->cancellation_reason,
                'cancellation_note' => $ride->cancellation_note,
                'pending_reschedule_at' => $ride->pending_reschedule_at?->toIso8601String(),
                'stops' => $ride->stops->map(fn (RideStop $stop) => [
                    'id' => $stop->id,
                    'sequence' => $stop->sequence,
                    'address' => $stop->address,
                    'sector' => $stop->sector?->name,
                    'leg_distance_km' => (float) $stop->leg_distance_km,
                    'leg_price' => (float) $stop->leg_price,
                    'status' => $stop->status,
                    'completed_at' => $stop->completed_at?->toIso8601String(),
                    'cancelled_at' => $stop->cancelled_at?->toIso8601String(),
                ]),
            ],
            'reviews' => $reviews,
        ]);
    }

    /**
     * Borra una carrera de prueba y todo lo que le pertenece (pedido
     * explícito del usuario: "que elimine todo lo referente a la carrera,
     * calificaciones... recalcular... conteo de carreras... y todo lo
     * relacionado").
     *
     * Reseñas, incidentes de Expreso, alertas SOS y mensajes del chat de esa
     * carrera ya tienen `cascadeOnDelete()` en su FK a `rides.id` (ver
     * migraciones de `reviews`, `express_incidents`, `sos_alerts` y
     * `ride_messages`) — se van solos al borrar la carrera. El promedio de
     * calificación y el conteo de carreras de conductor/cliente tampoco
     * quedan en ninguna columna cacheada: se calculan siempre al vuelo con
     * una query (`reviewsReceived()->avg('rating')`, `Ride::where(...)->count()`),
     * así que también quedan correctos solos, sin tocar nada más. Lo único
     * que SÍ hay que corregir a mano son los puntos del conductor
     * (`driver_profiles.total_points`), porque ese es un contador que solo
     * suma (ver RideController::complete()), nunca se recalcula solo.
     */
    public function destroy(Request $request, Ride $ride): RedirectResponse
    {
        $summary = [
            'ride_id' => $ride->id,
            'client_user_id' => $ride->client_user_id,
            'driver_user_id' => $ride->driver_user_id,
            'status' => $ride->status,
            'price' => (string) $ride->price,
            'points_earned' => $ride->points_earned,
        ];

        DB::transaction(function () use ($ride) {
            if ($ride->status === 'completed' && $ride->points_earned) {
                $driverProfile = DriverProfile::where('user_id', $ride->driver_user_id)->first();

                if ($driverProfile) {
                    $driverProfile->forceFill([
                        'total_points' => max(0, $driverProfile->total_points - $ride->points_earned),
                    ])->save();
                }
            }

            $rideRequestId = $ride->ride_request_id;

            $ride->delete();

            // La solicitud que originó esta carrera no se borra sola (el
            // cascade va al revés: borrar la solicitud borra la carrera, no
            // lo contrario) — si no se borra acá, queda huérfana dando
            // vueltas. Si venía de un Expreso, esto borra solo la solicitud
            // de ESE día puntual, nunca la ruta recurrente (ExpressRoute).
            RideRequest::whereKey($rideRequestId)->delete();
        });

        AdminAuditLogger::log(
            adminUserId: $request->user()->id,
            action: 'ride.delete',
            module: 'carreras',
            oldValue: $summary,
        );

        Log::warning('Carrera eliminada por un admin (depuración).', ['admin_id' => $request->user()->id] + $summary);

        return back()->with('status', 'Se eliminó la carrera y todo lo relacionado (reseñas, puntos, conteos).');
    }
}
