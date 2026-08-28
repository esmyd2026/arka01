<?php

namespace App\Http\Controllers;

use App\Events\RideCompleted;
use App\Events\RideRescheduleProposed;
use App\Events\RideRescheduleResponded;
use App\Models\DriverProfile;
use App\Models\FleetMember;
use App\Models\RatingReason;
use App\Models\Review;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\RideStop;
use App\Notifications\RideCompletedPushNotification;
use App\Notifications\RideReschedulePushNotification;
use App\Notifications\RideRescheduleResponsePushNotification;
use App\Services\Ride\IncomingRideRequestFinder;
use App\Services\Ride\RideLifecycle;
use App\Services\RideDispatchAdvancer;
use App\Services\WhatsAppFreeformSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RideController extends Controller
{
    public function __construct(
        private readonly IncomingRideRequestFinder $incomingRideRequestFinder,
        private readonly RideLifecycle $rideLifecycle,
    ) {}

    /**
     * Solicitudes que el cliente todavía tiene que ver o responder.
     * Compartido por la carga completa y la reconciliación liviana para
     * que ambas rutas apliquen exactamente el mismo criterio de estado.
     *
     * @return Collection<int, RideRequest>
     */
    private function pendingRequestsForClient(int $userId): Collection
    {
        return RideRequest::query()
            ->where('client_user_id', $userId)
            ->whereIn('status', ['pending', 'negotiating', 'waiting'])
            ->with(['driver', 'negotiatingDriver', 'originSector', 'destinationSector'])
            ->latest()
            ->get();
    }

    public function syncRequests(Request $request): JsonResponse
    {
        return response()->json([
            'pending_requests_as_client' => $this->pendingRequestsForClient($request->user()->id),
            'incoming_requests_as_driver' => $this->incomingRideRequestFinder->forDriver($request->user()),
        ]);
    }

    /**
     * "Carreras" (ítem de la barra inferior, sección 9.9): solicitudes
     * pendientes, carrera activa e historial reciente, tanto del lado cliente
     * como del lado conductor de este mismo usuario.
     */
    public function index(Request $request): Response
    {
        $userId = $request->user()->id;

        $pendingRequestsAsClient = $this->pendingRequestsForClient($userId);
        $incomingRequestsAsDriver = $this->incomingRideRequestFinder->forDriver($request->user());

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

        // Rediseño (pedido explícito del usuario): historial paginado en vez
        // de un tope fijo de 20 sin forma de ver más atrás — nombre de página
        // 'historial' propio, para no chocar si esta pantalla suma otro
        // paginado más adelante.
        $rideHistory = Ride::query()
            ->where(fn ($query) => $query->where('client_user_id', $userId)->orWhere('driver_user_id', $userId))
            ->whereNotIn('status', ['in_progress', 'scheduled'])
            ->with(['client:id,name,avatar_path', 'driver:id,name,avatar_path'])
            ->latest()
            ->paginate(10, ['*'], 'historial')
            ->withQueryString();

        // Pedido explícito del usuario: recordatorio si hay carreras
        // completadas todavía sin calificar de mi parte — cliente y
        // conductor califican de forma independiente (ninguno espera al
        // otro), así que esto se calcula igual para los dos lados. Solo
        // hace falta para las carreras de ESTA página del historial.
        $myReviewedRideIds = Review::query()
            ->where('reviewer_user_id', $userId)
            ->whereIn('ride_id', $rideHistory->getCollection()->where('status', 'completed')->pluck('id'))
            ->pluck('ride_id');

        $rideHistory->through(fn (Ride $ride) => [
            'id' => $ride->id,
            'client' => ['id' => $ride->client_user_id, 'name' => $ride->client?->name ?? 'Cuenta eliminada', 'avatar_url' => $ride->client?->avatar_url],
            'driver' => ['id' => $ride->driver_user_id, 'name' => $ride->driver?->name ?? 'Cuenta eliminada', 'avatar_url' => $ride->driver?->avatar_url],
            'status' => $ride->status,
            'price' => (float) $ride->price,
            // Fecha/hora real del hecho, no solo de cuándo se creó la fila
            // (pedido explícito del usuario: "coloquele al menos la fecha y
            // hora de la carrera").
            'occurred_at' => ($ride->completed_at ?? $ride->cancelled_at ?? $ride->created_at)->toIso8601String(),
            'needs_my_review' => $ride->status === 'completed' && ! $myReviewedRideIds->contains($ride->id),
        ]);

        // Alarma de "sin calificar" (pedido explícito del usuario): a
        // propósito una consulta aparte de $rideHistory, así la alarma
        // sigue siendo correcta sin importar en qué página del historial
        // esté parado el usuario.
        $unratedRideIds = Ride::query()
            ->where(fn ($query) => $query->where('client_user_id', $userId)->orWhere('driver_user_id', $userId))
            ->where('status', 'completed')
            ->whereNotIn('id', Review::query()->where('reviewer_user_id', $userId)->pluck('ride_id'))
            ->orderByDesc('completed_at')
            ->pluck('id');

        // Flotas donde este usuario es conductor activo: el frontend se suscribe
        // a cada una por WebSocket para recibir en vivo las solicitudes "a toda
        // la flota" (sección 3.5), además de su propio canal personal.
        $driverFleetIds = FleetMember::query()
            ->where('driver_user_id', $userId)
            ->whereNull('left_at')
            ->pluck('fleet_id');

        // Invitaciones de cooperativa pendientes (pedido explícito del
        // usuario: "le deberia llegar en la pantalla de solicitudes al
        // conductor tambien... como cuando un cliente le manda la
        // solicitud") — antes solo vivían en /cooperativas/invitaciones,
        // una pantalla aparte y fácil de no encontrar. Vacío para quien no
        // es conductor (la relación es por driver_user_id, no hace falta
        // gatear con isDriver()).
        $pendingCooperativeInvitations = $request->user()->cooperativeDriverMemberships()
            ->where('status', 'pending')
            ->with('cooperative.city')
            ->latest()
            ->get();

        return Inertia::render('Ride/Index', [
            'pendingRequestsAsClient' => $pendingRequestsAsClient,
            'incomingRequestsAsDriver' => $incomingRequestsAsDriver,
            'pendingCooperativeInvitations' => $pendingCooperativeInvitations,
            'activeRides' => $activeRides,
            'scheduledRides' => $scheduledRides,
            'rideHistory' => $rideHistory,
            'unratedRideIds' => $unratedRideIds,
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
        // stops: paradas adicionales (pedido explícito del usuario), vacío
        // en la gran mayoría de las carreras.
        $ride->load(['client', 'driver.driverProfile', 'originSector', 'destinationSector', 'rideRequest', 'stops']);

        // Calificación del conductor (pedido explícito del usuario, con
        // mockup de referencia): la tarjeta de "En camino" necesita mostrar
        // su ⭐ de un vistazo — mismo cálculo que ya usa driverCardData() en
        // RideRequestController, asignado como atributo dinámico (mismo
        // patrón que `needs_my_review` más arriba, Eloquent lo serializa igual).
        $ride->driver->average_rating = round((float) $ride->driver->reviewsReceived()->avg('rating'), 1);
        $ride->driver->review_count = $ride->driver->reviewsReceived()->count();

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
            // Chat temporal cliente↔conductor (sección 10 del roadmap de
            // mejoras) — historial completo aunque el chat ya esté "cerrado"
            // (carrera completada/cancelada), sigue siendo consulta normal
            // del historial de esa carrera, solo que ya no se puede escribir
            // más (ver Ride::chatIsOpen() y RideMessageController::store()).
            'messages' => $ride->messages()->with('sender')->oldest()->get(),
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

        $url = URL::temporarySignedRoute('public.rides.track', now()->addHours(24), ['ride' => $ride->public_id]);

        return response()->json(['url' => $url]);
    }

    /**
     * Recibe la posición del conductor mientras atiende ESTA carrera.
     * Es independiente del switch "Disponible": al aceptar un viaje puede
     * dejar de recibir solicitudes nuevas, pero debe continuar compartiendo
     * su recorrido con el cliente hasta completar o cancelar. Lógica real
     * en App\Services\Ride\RideLifecycle::updateLocation() (roadmap app
     * móvil, Hito 5).
     */
    public function updateLocation(Request $request, Ride $ride): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $arrivedAt = $this->rideLifecycle->updateLocation(
            $ride,
            $request->user(),
            (float) $validated['lat'],
            (float) $validated['lng'],
        );

        return response()->json([
            'ok' => true,
            'arrived_at' => $arrivedAt?->toIso8601String(),
        ]);
    }

    /**
     * El conductor arranca una carrera que venía de una solicitud PROGRAMADA
     * (consideración agregada al alcance) — hasta acá estaba en 'scheduled'
     * sin contar como "en carrera" en ningún lado, para no dejarlo "ocupado"
     * desde que aceptó hasta la hora programada. Solo el conductor puede
     * arrancarla (es quien decide cuándo sale a buscar al cliente de verdad).
     * Lógica real en App\Services\Ride\RideLifecycle::start().
     */
    public function start(Request $request, Ride $ride): RedirectResponse
    {
        $this->rideLifecycle->start($ride, $request->user());

        return back();
    }

    /**
     * "Ir por el pasajero" (bug real reportado por el usuario, con
     * captura: "ya estaba en camino... y cuando entro a la aplicación
     * decía nuevamente ir por el pasajero") — antes este toque solo vivía
     * en un ref local de Vue (a propósito, para no disparar el conteo de
     * cortesía de 5 minutos que sí dispara `arrived_at`), así que recargar
     * la página o volver a entrar perdía el estado. Se guarda acá, en una
     * columna aparte que nunca toca `arrived_at` ni ese conteo. Lógica
     * real en App\Services\Ride\RideLifecycle::headingToPassenger().
     */
    public function headingToPassenger(Request $request, Ride $ride): RedirectResponse
    {
        $this->rideLifecycle->headingToPassenger($ride, $request->user());

        return back();
    }

    /**
     * El conductor llegó al punto de encuentro (pedido explícito del
     * usuario) — todavía no recogió al cliente, solo avisa que ya está
     * esperando. Solo tiene sentido una vez ('in_progress' y sin marcar
     * antes); no bloquea nada más del flujo (el conductor igual puede
     * completar la carrera sin haber pasado por acá, por si se olvida).
     * Lógica real en App\Services\Ride\RideLifecycle::arrived().
     */
    public function arrived(Request $request, Ride $ride): RedirectResponse
    {
        $validated = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $this->rideLifecycle->arrived(
            $ride,
            $request->user(),
            isset($validated['lat']) ? (float) $validated['lat'] : null,
            isset($validated['lng']) ? (float) $validated['lng'] : null,
        );

        return back();
    }

    /**
     * El conductor recogió al cliente de verdad (pedido explícito del
     * usuario: guardar la fecha y hora para poder calcular esa información
     * después — tiempo de espera, duración real del viaje, etc.). Mismo
     * criterio que arrived(): no bloquea completar() si el conductor se
     * saltea este paso. Lógica real en App\Services\Ride\RideLifecycle::pickedUp().
     */
    public function pickedUp(Request $request, Ride $ride): RedirectResponse
    {
        $validated = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $this->rideLifecycle->pickedUp(
            $ride,
            $request->user(),
            isset($validated['lat']) ? (float) $validated['lat'] : null,
            isset($validated['lng']) ? (float) $validated['lng'] : null,
        );

        return back();
    }

    /**
     * Pedido explícito del usuario: la carrera la finaliza ÚNICAMENTE el
     * conductor (antes cualquiera de las dos partes podía) — después de esto
     * el siguiente paso es la calificación obligatoria, primero del cliente
     * y luego del conductor (ver ReviewController::store()). Lógica real en
     * App\Services\Ride\RideLifecycle::complete().
     */
    public function complete(Request $request, Ride $ride): RedirectResponse
    {
        $validated = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'completion_reason' => ['nullable', 'string', Rule::in(RideLifecycle::EARLY_COMPLETION_REASONS)],
            'completion_note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->rideLifecycle->complete(
            $ride,
            $request->user(),
            isset($validated['lat']) ? (float) $validated['lat'] : null,
            isset($validated['lng']) ? (float) $validated['lng'] : null,
            $validated['completion_reason'] ?? null,
            $validated['completion_note'] ?? null,
        );

        return back();
    }

    /**
     * Completa una parada intermedia (pedido explícito del usuario: "cada
     * parada se calcula diferente e individual... si no llegan a una
     * parada puedan pagarle cada parada y cancelar la otra o iniciar la
     * siguiente parada"). Con `cancel_rest`, cierra la carrera entera ahí
     * mismo (cobrando solo lo completado) — sin eso, la carrera sigue
     * `in_progress` y el conductor sigue a la próxima parada o al destino.
     */
    public function completeStop(Request $request, Ride $ride, RideStop $stop): RedirectResponse
    {
        if ($ride->driver_user_id !== $request->user()->id) {
            abort(403);
        }

        if ($stop->ride_id !== $ride->id) {
            abort(404);
        }

        if ($ride->status !== 'in_progress' || ! $ride->picked_up_at) {
            throw ValidationException::withMessages([
                'ride' => 'Esta carrera no está en curso, o todavía no se recogió al cliente.',
            ]);
        }

        // No se puede saltear el orden: solo la próxima parada pendiente
        // (la de menor `sequence` que no esté completada/cancelada) se
        // puede completar ahora.
        $nextPendingStop = $ride->stops()->whereNotIn('status', ['completed', 'cancelled'])->first();
        if (! $nextPendingStop || $nextPendingStop->id !== $stop->id) {
            throw ValidationException::withMessages([
                'ride' => 'Esta no es la próxima parada pendiente.',
            ]);
        }

        $validated = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'cancel_rest' => ['sometimes', 'boolean'],
        ]);

        // Mismo radio/criterio permisivo que arrived()/pickedUp() (1.5 km) —
        // el radio de 20 m de complete() quedó a propósito solo para el
        // destino final, no para paradas intermedias.
        $this->rideLifecycle->assertNearRideLocation(
            isset($validated['lat']) ? (float) $validated['lat'] : null,
            isset($validated['lng']) ? (float) $validated['lng'] : null,
            (float) $stop->lat,
            (float) $stop->lng,
            'Parece que todavía no está en la parada — inténtelo cuando esté más cerca.',
        );

        $stop->update(['status' => 'completed', 'completed_at' => now()]);

        if ($validated['cancel_rest'] ?? false) {
            $ride->stops()
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            $ride->update([
                'status' => 'completed',
                'completed_at' => now(),
                // Solo lo que de verdad se recorrió y se cobró — el tramo
                // final (hasta el destino) nunca pasó, así que `price` no
                // entra acá (a diferencia de complete(), que sí lo suma).
                'settled_price' => $ride->stops()->where('status', 'completed')->sum('leg_price'),
                'points_earned' => $ride->distance_km >= 5 ? 2 : 1,
            ]);

            DriverProfile::where('user_id', $ride->driver_user_id)->increment('total_points', $ride->points_earned);
            broadcast(new RideCompleted($ride))->toOthers();
            RideDispatchAdvancer::activateNextWaitingRequest();
            $ride->client->notify(new RideCompletedPushNotification($ride));
            WhatsAppFreeformSender::sendRideCompletedToClient($ride);
        }

        return back();
    }

    /**
     * Cancela una carrera YA ACEPTADA (pedido explícito del usuario: antes de
     * esto, no había ninguna forma — una vez aceptada, quedaba sin salida
     * hasta que se completara). Al principio solo podía el cliente; pedido
     * explícito del usuario, ahora también el conductor — cada uno con su
     * propia lista de motivos (CLIENT_CANCEL_REASONS/DRIVER_CANCEL_REASONS)
     * más una observación libre, opcional. Solo mientras la otra parte
     * todavía no la completó: si ya está en camino ('in_progress') o la
     * aceptó para más tarde ('scheduled'), se avisa a quien NO canceló por
     * WebSocket + push — importante sobre todo si ya iba en camino de
     * verdad. Se cuenta como cancelación real (`cancelled_at`) para poder
     * medirlo después (pedido explícito del usuario). Lógica real en
     * App\Services\Ride\RideLifecycle::cancel().
     */
    public function cancel(Request $request, Ride $ride): RedirectResponse
    {
        $userId = $request->user()->id;
        $isDriver = $ride->driver_user_id === $userId;

        if (! $isDriver && $ride->client_user_id !== $userId) {
            abort(403);
        }

        if (! in_array($ride->status, ['scheduled', 'in_progress'], true)) {
            throw ValidationException::withMessages([
                'ride' => 'Esta carrera ya no se puede cancelar.',
            ]);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', Rule::in($isDriver ? RideLifecycle::DRIVER_CANCEL_REASONS : RideLifecycle::CLIENT_CANCEL_REASONS)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->rideLifecycle->cancel($ride, $request->user(), $validated['reason'], $validated['note'] ?? null);

        return redirect()->route('rides.index');
    }

    /**
     * El cliente propone otro horario para una carrera programada que ya
     * aceptó un conductor (pedido explícito del usuario: "que puedan editar
     * una carrera programada si es que se equivocaron"). No se aplica
     * sola — el conductor ya se comprometió al horario original, así que
     * queda pendiente hasta que confirme o rechace el nuevo (mismo criterio
     * que la negociación de precio, ver RideRequestController::counter()).
     */
    public function proposeReschedule(Request $request, Ride $ride): RedirectResponse
    {
        if ($ride->client_user_id !== $request->user()->id) {
            abort(403);
        }

        if ($ride->status !== 'scheduled') {
            throw ValidationException::withMessages([
                'ride' => 'Esta carrera no está programada.',
            ]);
        }

        $validated = $request->validate([
            'scheduled_date' => ['required', 'date_format:Y-m-d'],
            'scheduled_time' => ['required', 'date_format:H:i'],
        ]);

        // Misma zona horaria explícita que RideRequestController::store() —
        // ver el bug real corregido ahí (config/app.php tenía 'UTC'
        // hardcodeado, corría la hora varias horas de más o de menos).
        $proposedAt = Carbon::createFromFormat(
            'Y-m-d H:i',
            "{$validated['scheduled_date']} {$validated['scheduled_time']}",
            config('app.timezone')
        );

        if ($proposedAt->isPast()) {
            throw ValidationException::withMessages([
                'scheduled_time' => 'La fecha y hora tiene que ser en el futuro.',
            ]);
        }

        $ride->update(['pending_reschedule_at' => $proposedAt]);

        broadcast(new RideRescheduleProposed($ride))->toOthers();

        $ride->driver->notify(new RideReschedulePushNotification($ride));

        return back()->with('status', 'Le mandamos el nuevo horario al conductor — queda a la espera de que lo confirme.');
    }

    /**
     * El conductor confirma el nuevo horario propuesto por el cliente —
     * recién acá se actualiza la fecha/hora real de la carrera
     * (`ride_requests.scheduled_at`, la fuente de verdad de siempre, ver
     * Ride::rideRequest()).
     */
    public function confirmReschedule(Request $request, Ride $ride): RedirectResponse
    {
        if ($ride->driver_user_id !== $request->user()->id) {
            abort(403);
        }

        if (! $ride->hasPendingReschedule()) {
            throw ValidationException::withMessages([
                'ride' => 'No hay ningún cambio de horario pendiente.',
            ]);
        }

        $ride->rideRequest->update(['scheduled_at' => $ride->pending_reschedule_at]);
        $ride->update(['pending_reschedule_at' => null]);

        broadcast(new RideRescheduleResponded($ride, true))->toOthers();

        $ride->client->notify(new RideRescheduleResponsePushNotification($ride, true));

        return back();
    }

    /**
     * El conductor rechaza el nuevo horario propuesto — la carrera sigue en
     * su horario original, sin tocar `ride_requests.scheduled_at`.
     */
    public function rejectReschedule(Request $request, Ride $ride): RedirectResponse
    {
        if ($ride->driver_user_id !== $request->user()->id) {
            abort(403);
        }

        if (! $ride->hasPendingReschedule()) {
            throw ValidationException::withMessages([
                'ride' => 'No hay ningún cambio de horario pendiente.',
            ]);
        }

        $ride->update(['pending_reschedule_at' => null]);

        broadcast(new RideRescheduleResponded($ride, false))->toOthers();

        $ride->client->notify(new RideRescheduleResponsePushNotification($ride, false));

        return back();
    }
}
