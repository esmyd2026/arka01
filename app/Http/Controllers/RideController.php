<?php

namespace App\Http\Controllers;

use App\Events\RideCancelled;
use App\Events\RideCompleted;
use App\Events\RideStarted;
use App\Models\DriverProfile;
use App\Models\FleetMember;
use App\Models\RatingReason;
use App\Models\Review;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Notifications\RideCancelledPushNotification;
use App\Notifications\RideCompletedPushNotification;
use App\Notifications\RideStartedPushNotification;
use App\Services\RideDispatchAdvancer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RideController extends Controller
{
    /**
     * "Carreras" (ítem de la barra inferior, sección 9.9): solicitudes
     * pendientes, carrera activa e historial reciente, tanto del lado cliente
     * como del lado conductor de este mismo usuario.
     */
    public function index(Request $request): Response
    {
        $userId = $request->user()->id;

        // "pending" y "negotiating" (sección 5): mientras se negocia el precio
        // la solicitud sigue activa, todavía no hay carrera confirmada.
        // "waiting" (pedido explícito del usuario): todos los conductores
        // elegibles estaban ocupados al pedirla — sigue sin un candidato
        // puntual, pero el cliente la tiene que seguir viendo acá mientras
        // espera a que alguien se desocupe (ver RideDispatchAdvancer).
        $pendingRequestsAsClient = RideRequest::query()
            ->where('client_user_id', $userId)
            ->whereIn('status', ['pending', 'negotiating', 'waiting'])
            ->with(['driver', 'negotiatingDriver', 'originSector', 'destinationSector'])
            ->latest()
            ->get();

        $incomingRequestsAsDriver = RideRequest::query()
            ->where(function ($query) use ($userId) {
                $query->where('status', 'pending')
                    ->where(function ($query) use ($userId) {
                        $query->where('driver_user_id', $userId)
                            ->orWhere(function ($query) use ($userId) {
                                // Solicitudes "a toda la flota" en flotas donde este
                                // usuario es conductor activo.
                                $query->whereNull('driver_user_id')
                                    ->whereIn('fleet_id', function ($sub) use ($userId) {
                                        $sub->select('fleet_id')
                                            ->from('fleet_members')
                                            ->where('driver_user_id', $userId)
                                            ->whereNull('left_at');
                                    });
                            });
                    });
            })
            // Además, mis propias contraofertas todavía esperando al cliente.
            ->orWhere(function ($query) use ($userId) {
                $query->where('status', 'negotiating')->where('negotiating_driver_user_id', $userId);
            })
            ->with(['client', 'originSector', 'destinationSector'])
            ->latest()
            ->get();

        // Flotas donde deshabilité las solicitudes del cliente dueño (pedido
        // explícito del usuario) — una "a toda la flota" de esa flota no
        // debería aparecer acá, aunque siga siendo parte de ella.
        $disabledFleetIds = FleetMember::query()
            ->where('driver_user_id', $userId)
            ->where('requests_disabled', true)
            ->pluck('fleet_id');

        $incomingRequestsAsDriver = $incomingRequestsAsDriver
            // Zona de cobertura + cliente deshabilitado (pedido explícito del
            // usuario): una "a toda la flota" que me queda lejos de mi zona,
            // o de una flota donde deshabilité a ese cliente, no debería
            // aparecer acá tampoco, no solo en el aviso en vivo — las
            // dirigidas a mí ya se validaron al pedirse, siempre se muestran.
            ->filter(fn (RideRequest $rideRequest) => $rideRequest->driver_user_id === $userId
                || $rideRequest->negotiating_driver_user_id === $userId
                || (! $disabledFleetIds->contains($rideRequest->fleet_id)
                    && ($request->user()->driverProfile?->isWithinRangeOf((float) $rideRequest->origin_lat, (float) $rideRequest->origin_lng) ?? true)))
            ->values();

        // Perfil de confianza del cliente (sección 3.6 y 8: "app segura", el
        // conductor tiene que ver quién le pide la carrera antes de aceptar).
        // Consulta aparte (no withAvg/withCount encadenado por relación
        // belongsTo → hasMany, que Eloquent no resuelve así) — mismo patrón
        // que DriverDirectoryController. Mismos nombres de campo que
        // RideRequested::broadcastWith() para que la carga inicial y el aviso
        // en vivo por WebSocket se vean exactamente igual en la pantalla.
        $clientIds = $incomingRequestsAsDriver->pluck('client_user_id')->unique();

        $clientRatings = Review::query()
            ->whereIn('reviewee_user_id', $clientIds)
            ->selectRaw('reviewee_user_id, avg(rating) as avg_rating, count(*) as review_count')
            ->groupBy('reviewee_user_id')
            ->get()
            ->keyBy('reviewee_user_id');

        $incomingRequestsAsDriver->each(function (RideRequest $rideRequest) use ($clientRatings) {
            $rating = $clientRatings->get($rideRequest->client_user_id);

            $rideRequest->client_name = $rideRequest->client->name;
            $rideRequest->client_rating = $rating ? round((float) $rating->avg_rating, 1) : 0;
            $rideRequest->client_review_count = $rating->review_count ?? 0;
            $rideRequest->client_member_code = $rideRequest->client->member_code;
        });

        $activeRides = Ride::query()
            ->where(fn ($query) => $query->where('client_user_id', $userId)->orWhere('driver_user_id', $userId))
            ->where('status', 'in_progress')
            ->with(['client', 'driver'])
            ->latest()
            ->get();

        // Carreras que vienen de una solicitud PROGRAMADA ya aceptada, pero
        // que el conductor todavía no arrancó (consideración agregada al
        // alcance) — no son "en curso" (el conductor no está ocupado
        // todavía) ni "historial" (todavía no pasó nada), necesitan su
        // propia sección con la fecha/hora y, del lado del conductor, el
        // botón para arrancarla.
        $scheduledRides = Ride::query()
            ->where(fn ($query) => $query->where('client_user_id', $userId)->orWhere('driver_user_id', $userId))
            ->where('status', 'scheduled')
            ->with(['client', 'driver', 'rideRequest'])
            ->orderBy('created_at')
            ->get();

        $rideHistory = Ride::query()
            ->where(fn ($query) => $query->where('client_user_id', $userId)->orWhere('driver_user_id', $userId))
            ->whereNotIn('status', ['in_progress', 'scheduled'])
            ->with(['client', 'driver'])
            ->latest()
            ->limit(20)
            ->get();

        // Pedido explícito del usuario: recordatorio si hay carreras
        // completadas todavía sin calificar de mi parte — cliente y
        // conductor califican de forma independiente (ninguno espera al
        // otro), así que esto se calcula igual para los dos lados.
        $myReviewedRideIds = Review::query()
            ->where('reviewer_user_id', $userId)
            ->whereIn('ride_id', $rideHistory->where('status', 'completed')->pluck('id'))
            ->pluck('ride_id');

        $rideHistory->each(function (Ride $ride) use ($myReviewedRideIds) {
            $ride->needs_my_review = $ride->status === 'completed' && ! $myReviewedRideIds->contains($ride->id);
        });

        // Flotas donde este usuario es conductor activo: el frontend se suscribe
        // a cada una por WebSocket para recibir en vivo las solicitudes "a toda
        // la flota" (sección 3.5), además de su propio canal personal.
        $driverFleetIds = FleetMember::query()
            ->where('driver_user_id', $userId)
            ->whereNull('left_at')
            ->pluck('fleet_id');

        return Inertia::render('Ride/Index', [
            'pendingRequestsAsClient' => $pendingRequestsAsClient,
            'incomingRequestsAsDriver' => $incomingRequestsAsDriver,
            'activeRides' => $activeRides,
            'scheduledRides' => $scheduledRides,
            'rideHistory' => $rideHistory,
            'driverFleetIds' => $driverFleetIds,
        ]);
    }

    /**
     * Vista de una carrera puntual, con mapa en vivo mientras está en curso
     * (sección 8: reutiliza la misma infraestructura de ubicación en tiempo real).
     */
    public function show(Request $request, Ride $ride): Response
    {
        $userId = $request->user()->id;

        if ($ride->client_user_id !== $userId && $ride->driver_user_id !== $userId) {
            abort(403);
        }

        // rideRequest: solo para leer `scheduled_at` cuando la carrera viene
        // de una solicitud PROGRAMADA (consideración agregada al alcance).
        $ride->load(['client', 'driver.driverProfile', 'originSector', 'destinationSector', 'rideRequest']);

        // Mi reseña y la de la otra parte, si ya existen (sección 3.6: se ven
        // de inmediato, no hay mecánica de revelado a ciegas en esta fase).
        $reviews = Review::query()->where('ride_id', $ride->id)->get()->keyBy('reviewer_user_id');
        $otherUserId = $userId === $ride->client_user_id ? $ride->driver_user_id : $ride->client_user_id;

        // Motivos disponibles para MI calificación (pedido explícito del
        // usuario): distintos según quién califica a quién — un cliente
        // eligiendo entre los motivos "conductor → cliente" no tendría sentido.
        $direction = $userId === $ride->client_user_id ? 'client_to_driver' : 'driver_to_client';

        return Inertia::render('Ride/Show', [
            'ride' => $ride,
            'isDriver' => $ride->driver_user_id === $userId,
            'myReview' => $reviews->get($userId),
            'theirReview' => $reviews->get($otherUserId),
            'ratingReasons' => RatingReason::query()
                ->where('direction', $direction)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'text']),
        ]);
    }

    /**
     * Genera un enlace de solo lectura para compartir con un contacto de
     * confianza (sección 8): sin cuenta ni instalación, protegido por firma
     * temporal (no por login) — ver App\Http\Controllers\PublicRideTrackingController.
     */
    public function trackingLink(Request $request, Ride $ride): JsonResponse
    {
        $userId = $request->user()->id;

        if ($ride->client_user_id !== $userId && $ride->driver_user_id !== $userId) {
            abort(403);
        }

        $url = URL::temporarySignedRoute('public.rides.track', now()->addHours(24), ['ride' => $ride->id]);

        return response()->json(['url' => $url]);
    }

    /**
     * El conductor arranca una carrera que venía de una solicitud PROGRAMADA
     * (consideración agregada al alcance) — hasta acá estaba en 'scheduled'
     * sin contar como "en carrera" en ningún lado, para no dejarlo "ocupado"
     * desde que aceptó hasta la hora programada. Solo el conductor puede
     * arrancarla (es quien decide cuándo sale a buscar al cliente de verdad).
     */
    public function start(Request $request, Ride $ride): RedirectResponse
    {
        if ($ride->driver_user_id !== $request->user()->id) {
            abort(403);
        }

        if ($ride->status !== 'scheduled') {
            throw ValidationException::withMessages([
                'ride' => 'Esta carrera no está programada o ya arrancó.',
            ]);
        }

        $ride->update([
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        broadcast(new RideStarted($ride))->toOthers();

        $ride->client->notify(new RideStartedPushNotification($ride));

        return back();
    }

    /**
     * Pedido explícito del usuario: la carrera la finaliza ÚNICAMENTE el
     * conductor (antes cualquiera de las dos partes podía) — después de esto
     * el siguiente paso es la calificación obligatoria, primero del cliente
     * y luego del conductor (ver ReviewController::store()).
     */
    public function complete(Request $request, Ride $ride): RedirectResponse
    {
        if ($ride->driver_user_id !== $request->user()->id) {
            abort(403);
        }

        if ($ride->status !== 'in_progress') {
            throw ValidationException::withMessages([
                'ride' => 'Esta carrera ya no está en curso.',
            ]);
        }

        // Puntos por carrera completada (pedido explícito del usuario:
        // fidelizar el uso de la app — cada carrera pedida y cumplida por
        // acá suma, arreglar directo por WhatsApp no da nada). El corte de
        // distancia es a propósito una regla de código, no del panel admin
        // (el usuario pidió configurables las MEDALLAS, no esto) — 2 puntos
        // desde 5 km, 1 punto por debajo. Ajustable acá si se quiere otro corte.
        $pointsEarned = $ride->distance_km >= 5 ? 2 : 1;

        $ride->update([
            'status' => 'completed',
            'completed_at' => now(),
            'points_earned' => $pointsEarned,
        ]);

        // increment() es atómico (sin condición de carrera si dos carreras
        // se completan casi al mismo tiempo) — ver App\Models\DriverTier::forPoints()
        // para cómo esto se traduce en la medalla vigente del conductor.
        DriverProfile::where('user_id', $ride->driver_user_id)->increment('total_points', $pointsEarned);

        // El conductor queda libre de nuevo (consideración agregada al
        // alcance) — sin esto, "Mi flota" y "¿A quién se la pedís?" seguían
        // mostrándolo "en carrera" hasta que alguien recargara la pantalla.
        broadcast(new RideCompleted($ride))->toOthers();

        // Lista de espera (pedido explícito del usuario: "puedo dejar la
        // carrera pendiente hasta que uno se desocupe y me atienda") — este
        // conductor recién liberado puede ser justo lo que le faltaba a
        // alguien que estaba esperando. Va DESPUÉS del update() de arriba:
        // recién ahí el conductor deja de contar como "ocupado".
        RideDispatchAdvancer::activateNextWaitingRequest();

        // Aviso push al cliente (pedido explícito del usuario: notificaciones
        // habilitadas en cada acción) — para que se entere y pueda calificar,
        // aunque tenga la app cerrada.
        $ride->client->notify(new RideCompletedPushNotification($ride));

        return back();
    }

    /**
     * El cliente cancela una carrera YA ACEPTADA (pedido explícito del
     * usuario: antes de esto, no había ninguna forma — una vez aceptada, el
     * cliente quedaba sin salida hasta que se completara). Solo el cliente
     * puede, y solo mientras el conductor todavía no la completó: si ya está
     * en camino ('in_progress') o la aceptó para más tarde ('scheduled'), se
     * avisa al conductor por WebSocket + push — importante sobre todo si ya
     * iba en camino de verdad. Se cuenta como cancelación real (`cancelled_at`)
     * para poder medirlo después (pedido explícito del usuario).
     */
    public function cancel(Request $request, Ride $ride): RedirectResponse
    {
        if ($ride->client_user_id !== $request->user()->id) {
            abort(403);
        }

        if (! in_array($ride->status, ['scheduled', 'in_progress'], true)) {
            throw ValidationException::withMessages([
                'ride' => 'Esta carrera ya no se puede cancelar.',
            ]);
        }

        $ride->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        // El conductor queda libre de nuevo (mismo criterio que al completar
        // una carrera) — si iba en camino, esto lo saca de "en carrera" en
        // todos lados sin esperar a que recargue.
        broadcast(new RideCancelled($ride))->toOthers();

        $ride->driver->notify(new RideCancelledPushNotification($ride));

        return redirect()->route('rides.index');
    }
}
