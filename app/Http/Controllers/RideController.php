<?php

namespace App\Http\Controllers;

use App\Events\DriverLocationUpdated;
use App\Events\RideArrived;
use App\Events\RideCancelled;
use App\Events\RideCompleted;
use App\Events\RidePickedUp;
use App\Events\RideRescheduleProposed;
use App\Events\RideRescheduleResponded;
use App\Events\RideStarted;
use App\Models\DriverProfile;
use App\Models\FleetMember;
use App\Models\RatingReason;
use App\Models\Review;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\RideStop;
use App\Notifications\RideArrivedPushNotification;
use App\Notifications\RideCancelledPushNotification;
use App\Notifications\RideCompletedPushNotification;
use App\Notifications\RidePickedUpPushNotification;
use App\Notifications\RideReschedulePushNotification;
use App\Notifications\RideRescheduleResponsePushNotification;
use App\Notifications\RideStartedPushNotification;
use App\Services\Haversine;
use App\Services\RideDispatchAdvancer;
use App\Services\WhatsAppFreeformSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RideController extends Controller
{
    /**
     * Motivos de cancelación (pedido explícito del usuario: "que agregue un
     * motivo") — lista fija de código, no un catálogo administrable como
     * `RatingReason`: acá alcanza con unas pocas opciones reales por lado,
     * sin necesidad de mantenimiento desde el panel admin.
     */
    private const CLIENT_CANCEL_REASONS = [
        'Cambié de planes',
        'Encontré otro medio de transporte',
        'Pedí la carrera por error',
        'El conductor demoró demasiado',
        'Otro motivo',
    ];

    private const DRIVER_CANCEL_REASONS = [
        'Imprevisto personal',
        'Problema con el vehículo',
        'No voy a poder llegar a tiempo',
        'El cliente no responde o no aparece',
        'Motivo de seguridad',
        'Otro motivo',
    ];

    /**
     * Pedido explícito del usuario: si el conductor completa la carrera
     * estando lejos del destino, tiene que decir por qué — misma lógica que
     * CLIENT_CANCEL_REASONS/DRIVER_CANCEL_REASONS de arriba (lista fija en
     * código, no un catálogo administrable: alcanza con unas pocas opciones
     * reales, sin necesidad de mantenimiento desde el panel admin).
     */
    private const EARLY_COMPLETION_REASONS = [
        'El cliente pidió terminar el viaje antes de llegar',
        'El cliente no colocó la ubicación de destino correcta',
        'No se puede llegar hasta el punto exacto (acceso cerrado, obra, tráfico, etc.)',
        'Problema con el GPS del celular',
        'Otro motivo',
    ];

    /**
     * Pedido explícito del usuario: "validá que las acciones del conductor
     * en una carrera... estén acorde a la ubicación de origen [o] destino" —
     * un radio generoso a propósito. El GPS de un celular en una ciudad
     * puede errar 100-300 m fácil, y el pin de origen/destino que puso el
     * cliente puede caer del otro lado de la cuadra — la idea es agarrar un
     * "marcó llegada estando a kilómetros de ahí" real, no trabar a un
     * conductor de verdad por un margen de error normal.
     */
    private const RIDE_ACTION_LOCATION_TOLERANCE_KM = 1.5;

    // Detección automática de llegada al punto de recogida. Es mucho más
    // estricta que el botón manual: 150 m tolera el error normal del GPS y
    // que el pin esté al otro lado de una cuadra, sin marcar llegada desde
    // una zona lejana.
    private const AUTOMATIC_PICKUP_ARRIVAL_RADIUS_KM = 0.15;

    /**
     * Pedido explícito del usuario: "el conductor podrá marcar como
     * completada la carrera cuando se encuentre a unos 20 metros" del
     * destino — mucho más estricto que RIDE_ACTION_LOCATION_TOLERANCE_KM
     * (1.5 km, que sigue aplicando tal cual a arrived()/pickedUp()). Acá
     * adentro de este radio no hace falta motivo; más lejos, sí.
     */
    private const RIDE_COMPLETION_ARRIVAL_RADIUS_KM = 0.02;

    /**
     * Pedido explícito del usuario: "validá que las acciones del
     * conductor... estén acorde a la ubicación de origen [o] destino" — el
     * frontend manda la posición fresca del navegador en el momento mismo
     * del clic (`Ride/Show.vue`, no la `driver_profiles.current_lat/lng` en
     * vivo, que solo se actualiza mientras el conductor está "disponible" y
     * podría estar vieja). Sin coordenadas (el navegador la negó, no la
     * soporta, o dio timeout) NO se bloquea la acción — mismo criterio
     * "permisivo si falta el dato" que ya usa `DriverProfile::isWithinRangeOf()`
     * en el resto de la app: mejor dejar pasar una acción real que trabar a
     * un conductor por un permiso que puede rechazar.
     */
    private function assertNearRideLocation(Request $request, float $targetLat, float $targetLng, string $message): void
    {
        if (! $request->filled('lat') || ! $request->filled('lng')) {
            return;
        }

        $validated = $request->validate([
            'lat' => ['numeric', 'between:-90,90'],
            'lng' => ['numeric', 'between:-180,180'],
        ]);

        $distanceKm = Haversine::distanceKm($targetLat, $targetLng, (float) $validated['lat'], (float) $validated['lng']);

        if ($distanceKm > self::RIDE_ACTION_LOCATION_TOLERANCE_KM) {
            $distanceLabel = $distanceKm < 1
                ? round($distanceKm * 1000).' m'
                : number_format($distanceKm, 1).' km';

            throw ValidationException::withMessages([
                'ride' => $message.' Su ubicación está a '.$distanceLabel.' del punto requerido.',
            ]);
        }
    }

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

        $url = URL::temporarySignedRoute('public.rides.track', now()->addHours(24), ['ride' => $ride->id]);

        return response()->json(['url' => $url]);
    }

    /**
     * Recibe la posición del conductor mientras atiende ESTA carrera.
     * Es independiente del switch "Disponible": al aceptar un viaje puede
     * dejar de recibir solicitudes nuevas, pero debe continuar compartiendo
     * su recorrido con el cliente hasta completar o cancelar.
     */
    public function updateLocation(Request $request, Ride $ride): JsonResponse
    {
        if ((int) $ride->driver_user_id !== (int) $request->user()->id) {
            abort(403);
        }

        if ($ride->status !== 'in_progress') {
            throw ValidationException::withMessages([
                'ride' => 'Esta carrera ya no admite actualizaciones de ubicación.',
            ]);
        }

        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $profile = $request->user()->driverProfile;

        if (! $profile) {
            abort(403, 'Todavía no activó su perfil de conductor.');
        }

        // No se modifica is_available: disponibilidad para nuevas carreras
        // y seguimiento del viaje actual son estados diferentes.
        $profile->update([
            'current_lat' => $validated['lat'],
            'current_lng' => $validated['lng'],
            'location_updated_at' => now(),
        ]);

        // La ubicación ya llega cada pocos segundos; aprovechar este mismo
        // ping evita otra petición del navegador. El lock impide que dos
        // lecturas simultáneas generen dos avisos de llegada.
        $automaticallyArrived = DB::transaction(function () use ($ride, $validated) {
            $lockedRide = Ride::query()->lockForUpdate()->findOrFail($ride->id);

            if ($lockedRide->status !== 'in_progress' || $lockedRide->arrived_at || $lockedRide->picked_up_at) {
                return null;
            }

            $distanceToPickup = Haversine::distanceKm(
                (float) $lockedRide->origin_lat,
                (float) $lockedRide->origin_lng,
                (float) $validated['lat'],
                (float) $validated['lng'],
            );

            if ($distanceToPickup > self::AUTOMATIC_PICKUP_ARRIVAL_RADIUS_KM) {
                return null;
            }

            $lockedRide->update(['arrived_at' => now()]);

            return $lockedRide->fresh(['client', 'driver']);
        });

        broadcast(new DriverLocationUpdated($profile))->toOthers();

        if ($automaticallyArrived) {
            broadcast(new RideArrived($automaticallyArrived))->toOthers();
            $automaticallyArrived->client->notify(new RideArrivedPushNotification($automaticallyArrived));
            WhatsAppFreeformSender::sendRideArrivedToClient($automaticallyArrived);
        }

        return response()->json([
            'ok' => true,
            'arrived_at' => $automaticallyArrived?->arrived_at?->toIso8601String(),
        ]);
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

        // No se puede arrancar con un horario en disputa (pedido explícito
        // del usuario, ver proposeReschedule()) — primero hay que
        // confirmarlo o rechazarlo.
        if ($ride->hasPendingReschedule()) {
            throw ValidationException::withMessages([
                'ride' => 'El cliente propuso otro horario — confirmalo o rechazalo antes de arrancar.',
            ]);
        }

        $ride->update([
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        broadcast(new RideStarted($ride))->toOthers();

        $ride->client->notify(new RideStartedPushNotification($ride));
        WhatsAppFreeformSender::sendRideStartedToClient($ride);

        return back();
    }

    /**
     * "Ir por el pasajero" (bug real reportado por el usuario, con
     * captura: "ya estaba en camino... y cuando entro a la aplicación
     * decía nuevamente ir por el pasajero") — antes este toque solo vivía
     * en un ref local de Vue (a propósito, para no disparar el conteo de
     * cortesía de 5 minutos que sí dispara `arrived_at`), así que recargar
     * la página o volver a entrar perdía el estado. Se guarda acá, en una
     * columna aparte que nunca toca `arrived_at` ni ese conteo.
     */
    public function headingToPassenger(Request $request, Ride $ride): RedirectResponse
    {
        if ($ride->driver_user_id !== $request->user()->id) {
            abort(403);
        }

        if ($ride->status !== 'in_progress') {
            throw ValidationException::withMessages([
                'ride' => 'Esta carrera no está en curso.',
            ]);
        }

        if ($ride->heading_to_passenger_at === null) {
            $ride->update(['heading_to_passenger_at' => now()]);
        }

        return back();
    }

    /**
     * El conductor llegó al punto de encuentro (pedido explícito del
     * usuario) — todavía no recogió al cliente, solo avisa que ya está
     * esperando. Solo tiene sentido una vez ('in_progress' y sin marcar
     * antes); no bloquea nada más del flujo (el conductor igual puede
     * completar la carrera sin haber pasado por acá, por si se olvida).
     */
    public function arrived(Request $request, Ride $ride): RedirectResponse
    {
        if ($ride->driver_user_id !== $request->user()->id) {
            abort(403);
        }

        if ($ride->status !== 'in_progress') {
            throw ValidationException::withMessages([
                'ride' => 'Esta carrera no está en curso.',
            ]);
        }

        if ($ride->arrived_at !== null) {
            throw ValidationException::withMessages([
                'ride' => 'Ya se había marcado como llegada.',
            ]);
        }

        $this->assertNearRideLocation(
            $request,
            (float) $ride->origin_lat,
            (float) $ride->origin_lng,
            'Parece que todavía no está en el punto de origen — inténtelo cuando esté más cerca.',
        );

        $ride->update(['arrived_at' => now()]);

        broadcast(new RideArrived($ride))->toOthers();

        $ride->client->notify(new RideArrivedPushNotification($ride));
        WhatsAppFreeformSender::sendRideArrivedToClient($ride);

        return back();
    }

    /**
     * El conductor recogió al cliente de verdad (pedido explícito del
     * usuario: guardar la fecha y hora para poder calcular esa información
     * después — tiempo de espera, duración real del viaje, etc.). Mismo
     * criterio que arrived(): no bloquea completar() si el conductor se
     * saltea este paso.
     */
    public function pickedUp(Request $request, Ride $ride): RedirectResponse
    {
        if ($ride->driver_user_id !== $request->user()->id) {
            abort(403);
        }

        if ($ride->status !== 'in_progress') {
            throw ValidationException::withMessages([
                'ride' => 'Esta carrera no está en curso.',
            ]);
        }

        if ($ride->picked_up_at !== null) {
            throw ValidationException::withMessages([
                'ride' => 'Ya se había marcado como recogido.',
            ]);
        }

        $this->assertNearRideLocation(
            $request,
            (float) $ride->origin_lat,
            (float) $ride->origin_lng,
            'Parece que todavía no está en el punto de origen — inténtelo cuando esté más cerca.',
        );

        $ride->update(['picked_up_at' => now()]);

        broadcast(new RidePickedUp($ride))->toOthers();

        // Pedido explícito del usuario (roadmap de mejoras, sección 5): cada
        // cambio de estado visible para el cliente necesita también un aviso
        // push, no solo el refresco en vivo por WebSocket.
        $ride->client->notify(new RidePickedUpPushNotification($ride));
        WhatsAppFreeformSender::sendRidePickedUpToClient($ride);

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

        // Paradas adicionales (pedido explícito del usuario): la carrera
        // solo se completa entera cuando ya no queda ninguna parada
        // pendiente — evita saltarse el orden. El conductor completa las
        // paradas una por una con completeStop() antes de llegar acá.
        if ($ride->stops()->whereNotIn('status', ['completed', 'cancelled'])->exists()) {
            throw ValidationException::withMessages([
                'ride' => 'Todavía queda una parada pendiente antes de completar la carrera.',
            ]);
        }

        $validated = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'completion_reason' => ['nullable', 'string', Rule::in(self::EARLY_COMPLETION_REASONS)],
            'completion_note' => ['nullable', 'string', 'max:500'],
        ]);

        // Pedido explícito del usuario: dentro de 20 m del destino completa
        // directo, como siempre. Más lejos (o sin coordenadas para
        // comprobarlo — mismo criterio permisivo que assertNearRideLocation())
        // hace falta elegir un motivo antes de dejarlo completar igual.
        $isNearDestination = ! isset($validated['lat'], $validated['lng']) || Haversine::distanceKm(
            (float) $ride->destination_lat,
            (float) $ride->destination_lng,
            (float) $validated['lat'],
            (float) $validated['lng'],
        ) <= self::RIDE_COMPLETION_ARRIVAL_RADIUS_KM;

        if (! $isNearDestination && ! ($validated['completion_reason'] ?? null)) {
            throw ValidationException::withMessages([
                'completion_reason' => 'Parece que todavía no llegó al destino — elija un motivo para completar la carrera igual.',
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
            // Solo se guarda si de verdad se completó lejos del destino — un
            // conductor que completa normal, cerca, no deja rastro de motivo.
            'completion_reason' => $isNearDestination ? null : $validated['completion_reason'] ?? null,
            'completion_note' => $isNearDestination ? null : ($validated['completion_note'] ?? null),
            // Monto real cobrado (pedido explícito del usuario): sin
            // paradas, es simplemente el precio de siempre — con paradas
            // (todas ya completadas, por el guard de arriba), se le suma lo
            // cobrado en cada tramo.
            'settled_price' => $ride->stops()->where('status', 'completed')->sum('leg_price') + $ride->price,
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
        WhatsAppFreeformSender::sendRideCompletedToClient($ride);

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
        $this->assertNearRideLocation(
            $request,
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
     * medirlo después (pedido explícito del usuario).
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
            'reason' => ['required', 'string', Rule::in($isDriver ? self::DRIVER_CANCEL_REASONS : self::CLIENT_CANCEL_REASONS)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $ride->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancelled_by' => $isDriver ? 'driver' : 'client',
            'cancellation_reason' => $validated['reason'],
            'cancellation_note' => $validated['note'] ?? null,
        ]);

        // Quien no canceló queda libre de nuevo si era el conductor (mismo
        // criterio que al completar una carrera) — si iba en camino, esto lo
        // saca de "en carrera" en todos lados sin esperar a que recargue.
        broadcast(new RideCancelled($ride))->toOthers();

        // A quien NO canceló, nunca a quien mandó la acción.
        $recipient = $isDriver ? $ride->client : $ride->driver;
        $recipient->notify(new RideCancelledPushNotification($ride));

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
