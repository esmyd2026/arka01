<?php

namespace App\Http\Controllers;

use App\Events\CooperativeRideUpdated;
use App\Events\RideRequestAccepted;
use App\Events\RideRequestCancelled;
use App\Events\RideRequestCountered;
use App\Events\RideRequestDeclined;
use App\Events\RideRequested;
use App\Events\RideRequestPriceRaised;
use App\Jobs\ExpireRideOffer;
use App\Jobs\ExpireWaitingRideRequest;
use App\Jobs\FallbackCooperativeAssignment;
use App\Models\City;
use App\Models\ClientCooperative;
use App\Models\Cooperative;
use App\Models\DriverProfile;
use App\Models\DriverTier;
use App\Models\Fleet;
use App\Models\PricingSetting;
use App\Models\Ride;
use App\Models\RidePriceOffer;
use App\Models\RideRequest;
use App\Models\User;
use App\Notifications\CooperativeRideAssignedPushNotification;
use App\Notifications\CooperativeRideRequestedPushNotification;
use App\Notifications\RideAcceptedPushNotification;
use App\Notifications\RideRequestCounteredPushNotification;
use App\Notifications\RideRequestDeclinedPushNotification;
use App\Notifications\RideRequestedPushNotification;
use App\Services\FrequentPlaces;
use App\Services\Haversine;
use App\Services\PlanLimits;
use App\Services\PriceCalculator;
use App\Services\RideDispatchAdvancer;
use App\Services\RideDispatchCandidates;
use App\Services\SmartDispatchScorer;
use App\Services\WhatsAppFreeformSender;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RideRequestController extends Controller
{
    /** Pedido explícito del usuario: "pedir una carrera" es del lado cliente. */
    private const SINGLE_ROLE_MESSAGE = 'Los conductores no pueden pedir una carrera — cada cuenta es cliente o conductor, no ambas.';

    /**
     * Pedido explícito del usuario: no alcanza con "en el futuro" — programar
     * para dentro de 10 minutos no le da tiempo real a nadie de organizarse.
     */
    private const MIN_SCHEDULING_LEAD_HOURS = 2;

    /**
     * Pedido explícito del usuario: si se dirige a un conductor puntual, no
     * se le puede programar otra carrera pisando un horario que ya tiene
     * comprometido (ver el chequeo de conflicto en store()).
     */
    private const SCHEDULED_CONFLICT_BUFFER_MINUTES = 60;

    /**
     * Bug reportado por el usuario (encontrado en una auditoría del flujo
     * completo de despacho): una solicitud INMEDIATA dirigida a un
     * conductor puntual (no de una bolsa) nunca tenía vencimiento — si ese
     * conductor no abría la app ni respondía, la solicitud quedaba
     * 'pending' para siempre, sin cascada (no hay a quién más ofrecérsela)
     * y sin ningún aviso al cliente. Más generosa que los 30 seg. de la
     * bolsa (ahí sí hay cascada de respaldo si alguien no contesta rápido;
     * acá el cliente eligió a esta persona a propósito, merece más tiempo
     * real antes de darla por perdida) — ver el nuevo branch en store() y
     * el guard relajado en RideDispatchAdvancer::advanceOrExpire().
     */
    private const DIRECTED_REQUEST_TIMEOUT_SECONDS = 300;

    public function __construct(private readonly PlanLimits $planLimits) {}

    /**
     * Pantalla "Solicitar carrera" (sección 3.5 y 9.5-A): el cliente ve a los
     * conductores activos de su flota, con su disponibilidad y última posición
     * conocida, para elegir uno puntual o mandarla a "toda la flota disponible".
     *
     * Con multi-flota (sección 7.3), el query param ?flota=ID indica cuál; si
     * no viene (por ejemplo desde el menú principal), se usa la primera flota
     * del cliente, igual que el comportamiento de antes de la Fase 5.
     */
    public function create(Request $request): Response|RedirectResponse
    {
        // Cada cuenta es cliente o conductor, nunca las dos (sección 3.1) —
        // mismo criterio que FleetController::index(). Sin esto, un
        // conductor que entrara por URL directa se terminaba provisionando
        // una flota propia solo por pisar esta pantalla (resolveFleet() la
        // crea sola si no existe).
        if (! $request->user()->isClient()) {
            return redirect()->route('dashboard')->with('status', self::SINGLE_ROLE_MESSAGE);
        }

        $preselectedDriverId = $request->filled('conductor')
            ? User::query()->where('public_id', $request->query('conductor'))->value('id')
            : null;

        $fleet = $this->resolveFleet($request, $request->query('flota'));
        $fleet->load('activeMembers.driver.driverProfile');

        $fleets = Fleet::query()
            ->where('owner_user_id', $request->user()->id)
            ->orderBy('id')
            ->get(['id', 'name']);

        // Quién está ahora mismo manejando una carrera (sección 3.5 y 9.7):
        // sirve para pintar "en carrera" en vez de "disponible" aunque tenga
        // la disponibilidad prendida — no puede tomar una segunda a la vez.
        $busyDriverIds = Ride::query()->where('status', 'in_progress')->pluck('driver_user_id');

        $fleetDrivers = $fleet->activeMembers->map(function ($member) use ($busyDriverIds) {
            return $this->driverCardData($member->driver, $busyDriverIds);
        })->values();

        // Directorio público (sección 3.4: la red de respaldo cuando nadie de
        // la flota personal está disponible o el cliente prefiere ampliar la
        // búsqueda) — mismos datos que Directory/Index.vue, acotado a los
        // mejor calificados para no traer de más.
        $fleetDriverIds = $fleet->activeMembers->pluck('driver_user_id');

        $publicDrivers = DriverProfile::query()
            ->where('is_public', true)
            ->where('verification_status', '!=', 'rejected')
            // Pedido explícito del usuario ("pasarme a cliente"): con el
            // perfil pausado, la cuenta opera como cliente — no debe seguir
            // ofreciéndose como conductor en el directorio.
            ->whereNull('deactivated_at')
            ->whereNotIn('user_id', $fleetDriverIds)
            ->where('user_id', '!=', $request->user()->id)
            ->with('user')
            ->get()
            ->map(fn (DriverProfile $profile) => $this->driverCardData($profile->user, $busyDriverIds))
            ->sortByDesc(fn ($d) => [$d['average_rating'], $d['review_count']])
            ->take(20)
            ->values();

        return Inertia::render('Ride/Request', [
            'fleet' => $fleet,
            'fleets' => $fleets,
            'fleetDrivers' => $fleetDrivers,
            'publicDrivers' => $publicDrivers,
            // Zonas del Ecuador (consideración agregada al alcance): además
            // del mapa, el cliente indica el sector de origen/destino. Arranca
            // por defecto en la ciudad donde vive (Mi perfil).
            'cities' => City::query()->where('is_active', true)->with(['sectors' => fn ($q) => $q->where('is_active', true)->orderBy('name')])->orderBy('name')->get(),
            'defaultCityId' => $request->user()->city_id,
            // Pedido explícito del usuario: al elegir un conductor puntual
            // (desde "Mi flota", el directorio o su perfil), la pantalla de
            // pedir carrera tiene que arrancar con ESE conductor ya elegido,
            // no "toda la flota disponible". Se valida del lado del frontend
            // contra `fleetDrivers`/`publicDrivers` (si el id no aparece ahí,
            // simplemente no queda nada preseleccionado).
            'preselectedDriverId' => $preselectedDriverId,
            // Reintento después de rechazo/expiración: el cliente ya eligió
            // ruta y el botón indica la bolsa concreta. La pantalla abre esa
            // categoría directamente, sin volver a pedir destino.
            'initialCategory' => in_array($request->query('categoria'), ['fleet', 'public', 'all'], true)
                ? $request->query('categoria')
                : null,
            // Pedido explícito del usuario: acceso directo "Programar
            // carrera" desde el inicio del cliente — arranca esta pantalla ya
            // en modo "programada" en vez de "ahora mismo".
            'startScheduled' => $request->boolean('programar'),
            // Fix reportado por el usuario: el estimado del frontend se
            // calculaba solo como distancia × tarifa, sin enterarse de la
            // tarifa mínima (PriceCalculator::suggestedPrice ya la aplica del
            // lado del servidor) — mostraba "$0.31 estimado" para una carrera
            // que en realidad iba a cobrar el mínimo configurado. Con esto el
            // frontend puede replicar el mismo `max(...)` y avisar cuándo se
            // está aplicando el mínimo en vez del cálculo por km.
            'minimumFare' => (float) PricingSetting::current()->minimum_fare,
            // Pedido explícito del usuario ("guardá las que ya ha realizado
            // para que aparezcan como favoritas"): direcciones que este
            // cliente ya usó antes (de origen o de destino, da igual — "casa"
            // puede ser origen un día y destino al otro), para no tener que
            // volver a escribirlas/buscarlas cada vez.
            'frequentPlaces' => FrequentPlaces::forClient($request->user()->id),
            // Rediseño UX (pedido explícito del usuario): si se llega desde el
            // buscador "¿A dónde vas?" de Inicio, el destino ya viene elegido
            // — la pantalla arranca directo en el paso de elegir conductor,
            // sin volver a pedirlo acá.
            'initialDestination' => $request->filled('destination_lat') && $request->filled('destination_lng') ? [
                'lat' => (float) $request->query('destination_lat'),
                'lng' => (float) $request->query('destination_lng'),
                'address' => (string) $request->query('destination_address', ''),
                'sector_id' => $request->filled('destination_sector_id') ? (int) $request->query('destination_sector_id') : null,
            ] : null,
            // Pedido explícito del usuario (documento formal de ajuste UX,
            // sección 13): si el buscador de Inicio ya sabía la ubicación en
            // vivo del cliente, se manda de una vez como origen — evita que
            // esta pantalla vuelva a pedir geolocalización por su cuenta
            // (ver Ride/Request.vue, onMounted) cuando ya se resolvió antes.
            'initialOrigin' => $request->filled('origin_lat') && $request->filled('origin_lng') ? [
                'lat' => (float) $request->query('origin_lat'),
                'lng' => (float) $request->query('origin_lng'),
                'address' => (string) $request->query('origin_address', ''),
                'sector_id' => $request->filled('origin_sector_id') ? (int) $request->query('origin_sector_id') : null,
            ] : null,
            'initialOptions' => [
                'passenger_count' => min(8, max(1, (int) $request->query('passenger_count', 1))),
                'needs_trunk' => $request->boolean('needs_trunk'),
                'payment_method' => in_array($request->query('payment_method'), ['efectivo', 'transferencia'], true)
                    ? $request->query('payment_method')
                    : 'efectivo',
            ],
            // "Mis rutas" (pedido explícito del usuario): pares completos de
            // origen+destino guardados a propósito, con alias opcional —
            // distinto de frequentPlaces (direcciones sueltas, automáticas).
            'savedRoutes' => $request->user()->savedRoutes()->latest()->get(),
            'cooperatives' => Cooperative::query()
                ->where('status', 'approved')
                ->whereNull('suspended_at')
                ->whereHas('clientLinks', fn ($query) => $query->where('client_user_id', $request->user()->id))
                ->with('activeDriverMemberships.driver.driverProfile')
                ->withCount('activeDriverMemberships')
                ->orderBy('name')
                ->get(['id', 'public_id', 'name', 'logo_path', 'response_timeout_seconds', 'stand_lat', 'stand_lng'])
                ->map(function ($cooperative) use ($request) {
                    $lat = $request->query('origin_lat');
                    $lng = $request->query('origin_lng');
                    $cooperative->distance_km = $lat && $lng && $cooperative->stand_lat && $cooperative->stand_lng
                        ? round(Haversine::distanceKm((float) $lat, (float) $lng, (float) $cooperative->stand_lat, (float) $cooperative->stand_lng), 1)
                        : null;
                    $cooperative->average_rate_per_km = round((float) $cooperative->activeDriverMemberships
                        ->pluck('driver.driverProfile.rate_per_km')->filter()->avg(), 2);

                    return $cooperative;
                })->sortBy(fn ($cooperative) => $cooperative->distance_km ?? PHP_FLOAT_MAX)->values(),
            'preselectedCooperativeId' => $request->filled('cooperativa')
                ? (int) $request->query('cooperativa')
                : null,
        ]);
    }

    /**
     * Ficha de un conductor para la lista de "a quién se la pedís" (sección
     * 3.5 y consideración agregada al alcance): estado (disponible / en
     * carrera / desconectado) y categoría por reputación (diamante, oro,
     * plata, cobre), para priorizar visualmente sin tener que adivinar.
     */
    private function driverCardData(User $driver, Collection $busyDriverIds): array
    {
        $profile = $driver->driverProfile;
        $rating = round((float) $driver->reviewsReceived()->avg('rating'), 1);
        $reviewCount = $driver->reviewsReceived()->count();

        return [
            'user_id' => $driver->id,
            'name' => $driver->full_name,
            // Foto de perfil (pedido explícito del usuario, con mockup de
            // referencia): esta función arma el array a mano en vez de
            // serializar el modelo completo, así que `avatar_url` (que User
            // agrega solo vía $appends en el resto de la app) no llegaba acá.
            'avatar_url' => $driver->avatar_url,
            'rate_per_km' => $profile?->rate_per_km,
            // Pedido explícito del usuario: si este conductor declaró su
            // propia tarifa mínima (y no supera la de la plataforma), el
            // estimado del frontend tiene que replicarla — ver
            // referenceMinimumFare() y PriceCalculator::suggestedPrice().
            'minimum_fare' => $profile?->minimum_fare,
            'current_lat' => $profile?->current_lat,
            'current_lng' => $profile?->current_lng,
            // Zona de cobertura (pedido explícito del usuario): el frontend ya
            // calcula la distancia del cliente a cada conductor (Haversine, en
            // el navegador) para mostrarla — con esto puede marcar "fuera de
            // zona" al mismo tiempo, sin otra ida al servidor.
            'max_request_distance_km' => $profile?->max_request_distance_km,
            'average_rating' => $rating,
            'review_count' => $reviewCount,
            'tier' => DriverTier::forPoints($profile?->total_points ?? 0)->toBadge(),
            // La insignia de verificado depende de DOS cosas, no solo de que
            // un admin haya aprobado la licencia/vehículo: el plan vigente
            // del conductor tiene que incluirla (sección 7.2). Antes de este
            // fix, se mostraba igual sin importar el plan — un hueco real
            // entre lo que se promete en /admin/planes y lo que se aplicaba.
            'is_verified' => $profile?->verification_status === 'approved'
                && $this->planLimits->forDriver($driver)['verified_badge'],
            // Pedido explícito del usuario: para que el cliente vea con qué
            // vehículo cuenta cada conductor, y para filtrar por cantidad de
            // pasajeros/cajuela (ver Ride/Request.vue).
            'vehicle_make' => $profile?->vehicle_make,
            'vehicle_model' => $profile?->vehicle_model,
            'vehicle_color' => $profile?->vehicle_color,
            'vehicle_type' => $profile?->vehicleTypeLabel(),
            // Confidencialidad (pedido explícito del usuario): placa tapada,
            // no la completa — ver DriverProfile::maskedPlate().
            'vehicle_plate' => $profile?->maskedPlate(),
            'passenger_capacity' => $profile?->passenger_capacity,
            'has_trunk' => (bool) $profile?->has_trunk,
            'status' => match (true) {
                $busyDriverIds->contains($driver->id) => 'busy',
                // Bug reportado por el usuario: sin ping reciente de
                // ubicación (app en segundo plano, navegador cerrado, sin
                // señal), `is_available` puede seguir en true hasta que
                // corra el barrido de 2 minutos — no mostrarlo como
                // "disponible" mientras tanto, salvo que siga alcanzable por
                // WhatsApp (DriverProfile::isReachable()).
                (bool) $profile?->is_available && $profile->isReachable($driver->hasActiveWhatsAppSession()) => 'available',
                default => 'offline',
            },
        ];
    }

    /**
     * Crea la solicitud y arranca la negociación de precio (sección 5): el
     * cliente puede aceptar el precio sugerido tal cual, o mandar directamente
     * una contraoferta propia como primer movimiento.
     */
    public function store(Request $request): RedirectResponse
    {
        // Mismo criterio que create() — cada cuenta es cliente o conductor,
        // nunca las dos (sección 3.1) — CON UNA excepción puntual, pedida
        // explícitamente por el usuario tras probar el bot con su propio
        // número de conductor: pedir una carrera por WhatsApp con un número
        // que ya es de un conductor. `whatsapp_guest_booking` solo lo manda
        // WhatsAppRideBookingHandler::createRide() (ninguna pantalla web lo
        // envía nunca) y solo cuando quien escribe es de verdad conductor —
        // no admin ni cooperativa, esos siguen bloqueados. La cuenta no
        // cambia de rol ni gana acceso nuevo en la web: solo esta carrera
        // puntual queda registrada a su nombre.
        $isWhatsAppDriverGuestBooking = $request->boolean('whatsapp_guest_booking') && $request->user()->isDriver();
        if (! $request->user()->isClient() && ! $isWhatsAppDriverGuestBooking) {
            throw ValidationException::withMessages(['driver_user_id' => self::SINGLE_ROLE_MESSAGE]);
        }

        $validated = $request->validate([
            'fleet_id' => ['nullable', 'integer', 'exists:fleets,id'],
            'cooperative_id' => ['nullable', 'integer', 'exists:cooperatives,id'],
            'driver_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'origin_lat' => ['required', 'numeric', 'between:-90,90'],
            'origin_lng' => ['required', 'numeric', 'between:-180,180'],
            'origin_address' => ['nullable', 'string', 'max:255'],
            'origin_sector_id' => ['nullable', 'integer', 'exists:sectors,id'],
            'destination_lat' => ['required', 'numeric', 'between:-90,90'],
            'destination_lng' => ['required', 'numeric', 'between:-180,180'],
            'destination_address' => ['nullable', 'string', 'max:255'],
            'destination_sector_id' => ['nullable', 'integer', 'exists:sectors,id'],
            // Distancia real de manejo, la misma ruta que ya se dibuja en el
            // mapa (OSRM) — nunca obligatoria, ver el chequeo de cordura más
            // abajo antes de usarla en vez de la línea recta.
            'route_distance_km' => ['nullable', 'numeric', 'min:0'],
            // Si no manda un monto propio, se usa el precio sugerido del sistema.
            'offered_price' => ['nullable', 'numeric', 'min:0.01'],
            // "Ahora mismo" (default) o "programada" (consideración agregada al
            // alcance, pedido explícito del usuario) — programada exige fecha y
            // hora, validadas por separado porque llegan de dos inputs distintos
            // del formulario (más fácil de escribir que un datetime-local).
            // 'nullable' es imprescindible acá: en modo "ahora mismo" el
            // frontend manda estos dos campos como '' (string vacío, no
            // ausentes) — sin 'nullable', `date_format` los seguía validando
            // igual (una fecha vacía no matchea Y-m-d) aunque `required_if`
            // correctamente no los exigiera. Bug real reportado: bloqueaba
            // CUALQUIER carrera "para ahora" con un error de formato de fecha.
            'is_scheduled' => ['sometimes', 'boolean'],
            'scheduled_date' => ['nullable', 'required_if:is_scheduled,1', 'date_format:Y-m-d'],
            'scheduled_time' => ['nullable', 'required_if:is_scheduled,1', 'date_format:H:i'],
            'round_trip' => ['sometimes', 'boolean'],
            // Despacho secuencial estilo Uber (pedido explícito del usuario):
            // de qué "bolsa" salen los candidatos cuando no se elige un
            // conductor puntual — mi flota, el directorio público, o ambos
            // (el mismo toggle "¿A quién se la pedís?" de la pantalla).
            'dispatch_pool' => ['nullable', 'in:fleet,public,both'],
            // Pedido explícito del usuario: cuántos pasajeros van (por
            // defecto 1) y si hace falta cajuela para maletas (por defecto
            // no) — se usan para filtrar qué conductores pueden tomarla.
            'passenger_count' => ['sometimes', 'integer', 'min:1', 'max:8'],
            'needs_trunk' => ['sometimes', 'boolean'],
            // Forma de pago (pedido explícito del usuario): el cliente la
            // elige al pedir la carrera, para que el conductor la vea antes
            // de aceptar — "efectivo" de default si no manda nada.
            'payment_method' => ['sometimes', 'in:efectivo,transferencia'],
            // Observación libre del cliente (pedido explícito del usuario:
            // "que exista un campo que el cliente meta una observación que
            // no sea obligatoria") — nunca obligatoria.
            'notes' => ['nullable', 'string', 'max:500'],
            // Paradas adicionales (pedido explícito del usuario: "agregar
            // una parada adicional... solo permitir 4 paradas", cada una
            // cobrada por separado — ver stops_price más abajo). Nunca
            // negociables: el precio de cada una lo calcula el sistema,
            // solo el tramo final admite contraoferta (mismo criterio de
            // siempre, sin cambios).
            'stops' => ['sometimes', 'array', 'max:4'],
            'stops.*.lat' => ['required_with:stops', 'numeric', 'between:-90,90'],
            'stops.*.lng' => ['required_with:stops', 'numeric', 'between:-180,180'],
            'stops.*.address' => ['nullable', 'string', 'max:255'],
            'stops.*.sector_id' => ['nullable', 'integer', 'exists:sectors,id'],
            'stops.*.route_distance_km' => ['nullable', 'numeric', 'min:0'],
        ]);

        $needsTrunk = (bool) ($validated['needs_trunk'] ?? false);
        $passengerCount = (int) ($validated['passenger_count'] ?? 1);

        $isScheduled = (bool) ($validated['is_scheduled'] ?? false);

        // Una inmediata pendiente o en curso bloquea otra inmediata. Las
        // programadas no participan en este bloqueo: son reservas futuras y
        // el cliente puede seguir pidiendo transporte para ahora.
        if (! $isScheduled) {
            $hasImmediateRequest = RideRequest::query()
                ->where('client_user_id', $request->user()->id)
                ->where('is_scheduled', false)
                ->whereIn('status', ['pending', 'negotiating', 'waiting'])
                ->exists();
            $hasImmediateRide = Ride::query()
                ->where('client_user_id', $request->user()->id)
                ->where('status', 'in_progress')
                ->exists();

            if ($hasImmediateRequest || $hasImmediateRide) {
                throw ValidationException::withMessages([
                    'ride' => 'Ya tiene una carrera inmediata activa. Debe finalizarla o cancelarla antes de solicitar otra.',
                ]);
            }
        }
        $scheduledAt = null;

        if ($isScheduled) {
            // Bug real reportado por el usuario: la fecha/hora que elige el
            // cliente es SIEMPRE hora local de Ecuador, nunca UTC — sin la
            // zona horaria explícita acá, Carbon caía a la del servidor
            // (config('app.timezone'), que además tenía un bug propio de
            // fondo, ver config/app.php), corriendo la hora guardada varias
            // horas de más o de menos respecto a lo que el cliente escribió.
            $scheduledAt = Carbon::createFromFormat(
                'Y-m-d H:i',
                "{$validated['scheduled_date']} {$validated['scheduled_time']}",
                config('app.timezone')
            );

            // Pedido explícito del usuario: "en el futuro" a secas dejaba
            // programar para dentro de 5 minutos, sin darle tiempo real a
            // nadie de organizarse — ahora exige un mínimo de anticipación,
            // incluso para "el mismo día".
            if ($scheduledAt->lessThan(now()->addHours(self::MIN_SCHEDULING_LEAD_HOURS))) {
                throw ValidationException::withMessages([
                    'scheduled_time' => 'La hora programada tiene que ser al menos '.self::MIN_SCHEDULING_LEAD_HOURS.' horas después de ahora.',
                ]);
            }
        }

        $fleet = $this->resolveFleet($request, $validated['fleet_id'] ?? null);
        $cooperative = null;

        if (! empty($validated['cooperative_id'])) {
            $cooperative = Cooperative::query()->findOrFail($validated['cooperative_id']);
            $isLinked = ClientCooperative::query()
                ->where('client_user_id', $request->user()->id)
                ->where('cooperative_id', $cooperative->id)
                ->exists();

            if (! $isLinked || ! $cooperative->isApproved()) {
                throw ValidationException::withMessages([
                    'cooperative_id' => 'La cooperativa no pertenece a su red o todavía no está habilitada.',
                ]);
            }

            // La cooperativa administra la asignación; el cliente no puede
            // combinarla con un conductor puntual o con otra bolsa.
            $validated['driver_user_id'] = null;
            $validated['dispatch_pool'] = null;
        }

        if (! $cooperative && ! empty($validated['driver_user_id'])) {
            $isActiveMember = $fleet->activeMembers()
                ->where('driver_user_id', $validated['driver_user_id'])
                ->exists();

            // También se puede dirigir a un conductor del directorio público
            // (sección 3.4: la red de respaldo), no solo a los de la flota.
            $isPublicDriver = DriverProfile::query()
                ->where('user_id', $validated['driver_user_id'])
                ->where('is_public', true)
                ->exists();

            if (! $isActiveMember && ! $isPublicDriver) {
                throw ValidationException::withMessages([
                    'driver_user_id' => 'Ese conductor no es parte de su flota ni está en el directorio público.',
                ]);
            }

            $chosenProfile = DriverProfile::query()->with('user')->where('user_id', $validated['driver_user_id'])->first();

            // Pedido explícito del usuario ("pasarme a cliente"): con el
            // perfil pausado no puede recibir solicitudes, ni siquiera
            // dirigidas de su propia flota — la cuenta está operando como
            // cliente ahora mismo, no como conductor.
            if ($chosenProfile?->isDeactivated()) {
                throw ValidationException::withMessages([
                    'driver_user_id' => 'Ese conductor pausó su perfil — no puede recibir solicitudes en este momento.',
                ]);
            }

            // Una solicitud inmediata dirigida no puede saltarse el filtro
            // de ocupados que sí usa la bolsa automática. La carrera
            // programada se trata aparte porque puede aceptarse para más
            // adelante sin interrumpir el viaje actual.
            if (! $isScheduled && Ride::query()
                ->where('driver_user_id', $validated['driver_user_id'])
                ->where('status', 'in_progress')
                ->exists()) {
                throw ValidationException::withMessages([
                    'driver_user_id' => 'Ese conductor está atendiendo otra carrera. Elija otro conductor o solicite a toda su flota.',
                ]);
            }

            // Zona de cobertura (pedido explícito del usuario): un conductor
            // puede limitar hasta qué distancia de su ubicación quiere recibir
            // solicitudes — "así sea de mi flota, tiene que estar fuera de
            // rango" si no la cumple. Se valida ACÁ (no solo se oculta en el
            // formulario) para que no se pueda pedir igual manipulando el pedido.
            if ($chosenProfile && ! $chosenProfile->isWithinRangeOf((float) $validated['origin_lat'], (float) $validated['origin_lng'])) {
                throw ValidationException::withMessages([
                    'driver_user_id' => 'Ese conductor no recibe solicitudes tan lejos de su zona en este momento.',
                ]);
            }

            // Bug reportado por el usuario: si el conductor pasó la app a
            // segundo plano, cerró el navegador o se quedó sin señal,
            // `is_available` puede seguir en true hasta que corra el barrido
            // de 2 minutos — no dejar que le llegue una solicitud dirigida
            // mientras tanto, aunque el frontend todavía lo muestre activo.
            // Excepción (pedido explícito del usuario): si sigue con la
            // ventana de WhatsApp abierta, sigue siendo alcanzable aunque el
            // GPS esté viejo.
            if ($chosenProfile && ! $chosenProfile->isReachable($chosenProfile->user?->hasActiveWhatsAppSession() ?? false)) {
                throw ValidationException::withMessages([
                    'driver_user_id' => 'Ese conductor parece haberse desconectado — pruebe con otro o con toda su flota.',
                ]);
            }

            // El conductor deshabilitó las solicitudes de este cliente puntual
            // (pedido explícito del usuario) — sigue siendo de su flota, solo
            // que sus pedidos ya no le llegan.
            $requestsDisabled = $fleet->activeMembers()
                ->where('driver_user_id', $validated['driver_user_id'])
                ->value('requests_disabled');

            if ($requestsDisabled) {
                throw ValidationException::withMessages([
                    'driver_user_id' => 'Ese conductor no está aceptando sus solicitudes en este momento.',
                ]);
            }

            // Pedido explícito del usuario ("solo buscar los conductores que
            // tengan esa característica"): se valida acá también, no solo
            // se filtra en el frontend, para que no se pueda pedir igual
            // manipulando el pedido.
            if ($chosenProfile && $chosenProfile->passenger_capacity < $passengerCount) {
                throw ValidationException::withMessages([
                    'driver_user_id' => 'Ese conductor no tiene capacidad para esa cantidad de pasajeros.',
                ]);
            }

            if ($chosenProfile && $needsTrunk && ! $chosenProfile->has_trunk) {
                throw ValidationException::withMessages([
                    'driver_user_id' => 'Ese conductor no tiene cajuela disponible.',
                ]);
            }

            // Pedido explícito del usuario: si es programada y dirigida a un
            // conductor puntual, no se le puede pisar un horario que ya
            // tiene comprometido — se valida contra sus carreras YA
            // aceptadas (`rides.status = 'scheduled'`, no contra
            // solicitudes todavía pendientes de nadie) dentro de una
            // ventana de una hora antes/después (no se conoce cuánto dura
            // cada carrera, así que se trata cada una como si ocupara ese
            // bloque de tiempo).
            if ($isScheduled && $scheduledAt) {
                $hasConflict = Ride::query()
                    ->where('driver_user_id', $validated['driver_user_id'])
                    ->where('status', 'scheduled')
                    ->whereHas('rideRequest', function ($query) use ($scheduledAt) {
                        $query->whereBetween('scheduled_at', [
                            $scheduledAt->clone()->subMinutes(self::SCHEDULED_CONFLICT_BUFFER_MINUTES),
                            $scheduledAt->clone()->addMinutes(self::SCHEDULED_CONFLICT_BUFFER_MINUTES),
                        ]);
                    })
                    ->exists();

                if ($hasConflict) {
                    throw ValidationException::withMessages([
                        'scheduled_time' => 'Ese conductor ya tiene otra carrera programada cerca de ese horario.',
                    ]);
                }
            }
        }

        // Despacho secuencial estilo Uber (pedido explícito del usuario): sin
        // conductor puntual elegido, se arma la bolsa de candidatos (mi
        // flota / público / ambos, según el toggle de la pantalla) ordenada
        // por cercanía real. Para "ahora mismo" se le ofrece a uno a la vez
        // con 30 segundos para responder, cascada al siguiente si no
        // contesta a tiempo (App\Services\RideDispatchAdvancer). Para
        // "programada" no hay apuro — se mantiene el aviso a toda la flota
        // de siempre, sin cascada ni vencimiento.
        $driverUserId = $validated['driver_user_id'] ?? null;
        $dispatchPool = null;
        $offerCandidateIds = [];
        $currentOfferExpiresAt = null;
        $requestStatus = 'pending';

        $cooperativeCandidateIds = [];
        $cooperativeOfferExpiresAt = null;
        $cooperativeAssignmentStatus = null;

        if ($cooperative) {
            $cooperativeAssignmentStatus = $cooperative->automatic_assignment_enabled ? 'pending_assignment' : 'awaiting_operator';

            if (! $isScheduled && $cooperative->automatic_assignment_enabled) {
                $candidateIds = RideDispatchCandidates::forCooperative(
                    $cooperative,
                    (float) $validated['origin_lat'],
                    (float) $validated['origin_lng'],
                    $passengerCount,
                    $needsTrunk,
                );

                if (! empty($candidateIds)) {
                    $driverUserId = array_shift($candidateIds);
                    $offerCandidateIds = $candidateIds;
                    $cooperativeCandidateIds = $candidateIds;
                    $dispatchPool = 'cooperative';
                    $timeoutSeconds = max(15, min(300, (int) $cooperative->response_timeout_seconds));
                    $currentOfferExpiresAt = now()->addSeconds($timeoutSeconds);
                    $cooperativeOfferExpiresAt = $currentOfferExpiresAt;
                    $cooperativeAssignmentStatus = 'awaiting_driver';
                }
            }
        } elseif (! $driverUserId && ! $isScheduled) {
            $dispatchPool = $validated['dispatch_pool'] ?? 'fleet';

            $candidateIds = RideDispatchCandidates::forPool(
                $fleet,
                $request->user(),
                $dispatchPool,
                (float) $validated['origin_lat'],
                (float) $validated['origin_lng'],
                $passengerCount,
                $needsTrunk,
            );

            if (empty($candidateIds)) {
                // Lista de espera (pedido explícito del usuario: "puedo
                // dejar la carrera pendiente hasta que uno se desocupe y me
                // atienda") — solo tiene sentido esperar cuando el ÚNICO
                // motivo es que todos están ocupados ahora mismo; si nadie
                // está conectado, sin capacidad o fuera de zona, esperar no
                // cambia nada y se rechaza como siempre.
                if (RideDispatchCandidates::isEmptyOnlyBecauseEveryoneIsBusy(
                    $fleet,
                    $request->user(),
                    $dispatchPool,
                    (float) $validated['origin_lat'],
                    (float) $validated['origin_lng'],
                    $passengerCount,
                    $needsTrunk,
                )) {
                    $requestStatus = 'waiting';
                } else {
                    // Bug reportado por el usuario: el mensaje fijo de acá
                    // siempre culpaba a "pasajeros/cajuela" aunque el motivo
                    // real fuera otro (ej. zona de cobertura) — ahora se
                    // diagnostica cuál filtro vació la bolsa de verdad.
                    $reason = RideDispatchCandidates::explainEmptyPool(
                        $fleet,
                        $request->user(),
                        $dispatchPool,
                        (float) $validated['origin_lat'],
                        (float) $validated['origin_lng'],
                        $passengerCount,
                        $needsTrunk,
                    );

                    throw ValidationException::withMessages(['driver_user_id' => $reason]);
                }
            } else {
                $driverUserId = array_shift($candidateIds);
                $offerCandidateIds = $candidateIds;
                $currentOfferExpiresAt = now()->addSeconds(30);
            }
        } elseif ($driverUserId && ! $isScheduled) {
            // Bug reportado por el usuario: ver DIRECTED_REQUEST_TIMEOUT_SECONDS
            // arriba — sin esto, una solicitud dirigida a un conductor
            // puntual que nunca respondiera quedaba pendiente para siempre.
            $currentOfferExpiresAt = now()->addSeconds(self::DIRECTED_REQUEST_TIMEOUT_SECONDS);
        }

        // Bug real confirmado (pedido explícito del usuario: "probá el mapa...
        // en temas de km") — probando dos rutas reales de Guayaquil contra el
        // mismo servidor OSRM que ya usa el mapa, la línea recta (Haversine)
        // dio hasta un tercio de la distancia real de manejo en calles con
        // curvas o sin conexión directa. El frontend ya traza esa ruta real
        // (ver Utils/osrmRoute.js) — ahora manda también su distancia, y se
        // usa esa en vez de la línea recta cuando parece razonable (nunca
        // puede ser MENOR a la línea recta, la ruta real nunca es más corta
        // que ir directo; y se descarta si es descabelladamente mayor, señal
        // de una respuesta rara de OSRM en vez de confiar en cualquier cosa
        // que mande el navegador). Si no llegó o no pasa el chequeo, cae de
        // vuelta a Haversine — mismo criterio "gratis, sin key, con
        // respaldo si el servicio externo falla" que el resto de la app.
        $stopsInput = $validated['stops'] ?? [];

        // El tramo final (el que valida/paga/completa RideController::complete())
        // arranca en la ÚLTIMA parada cuando hay paradas, o en el origen
        // cuando no — pedido explícito del usuario: "cada parada se calcula
        // diferente e individual". Mismo chequeo de cordura Haversine-vs-ruta
        // real de siempre, solo que ahora el "origen" de ese chequeo puede
        // no ser el origen real de la carrera.
        $finalLegOriginLat = $stopsInput ? (float) end($stopsInput)['lat'] : (float) $validated['origin_lat'];
        $finalLegOriginLng = $stopsInput ? (float) end($stopsInput)['lng'] : (float) $validated['origin_lng'];

        $haversineKm = round(Haversine::distanceKm(
            $finalLegOriginLat,
            $finalLegOriginLng,
            (float) $validated['destination_lat'],
            (float) $validated['destination_lng'],
        ), 2);

        $routeDistanceKm = $validated['route_distance_km'] ?? null;
        $distanceKm = ($routeDistanceKm !== null && $routeDistanceKm >= $haversineKm * 0.95 && $routeDistanceKm <= $haversineKm * 5)
            ? round((float) $routeDistanceKm, 2)
            : $haversineKm;

        $ratePerKm = $cooperative
            ? (float) DriverProfile::query()
                ->whereIn('user_id', $cooperative->activeDriverMemberships()->pluck('driver_user_id'))
                ->whereNotNull('rate_per_km')
                ->avg('rate_per_km')
            : $this->referenceRatePerKm($fleet, $driverUserId);
        $driverMinimumFareForStops = $this->referenceMinimumFare($driverUserId);

        // Cada parada es un tramo propio: mismo chequeo de cordura Haversine
        // (contra el punto anterior: origen o la parada previa) y el mismo
        // PriceCalculator que usa el tramo final, pero uno por parada —
        // pedido explícito del usuario: "cada parada se calcula diferente e
        // individual... puedan pagarle cada parada".
        $stopsData = [];
        $stopsPrice = 0.0;
        $previousLat = (float) $validated['origin_lat'];
        $previousLng = (float) $validated['origin_lng'];

        foreach ($stopsInput as $index => $stopInput) {
            $legHaversineKm = round(Haversine::distanceKm($previousLat, $previousLng, (float) $stopInput['lat'], (float) $stopInput['lng']), 2);
            $legRouteDistanceKm = $stopInput['route_distance_km'] ?? null;
            $legDistanceKm = ($legRouteDistanceKm !== null && $legRouteDistanceKm >= $legHaversineKm * 0.95 && $legRouteDistanceKm <= $legHaversineKm * 5)
                ? round((float) $legRouteDistanceKm, 2)
                : $legHaversineKm;

            $legPrice = PriceCalculator::suggestedPrice($legDistanceKm, $ratePerKm, driverMinimumFare: $driverMinimumFareForStops)['total'];
            $stopsPrice += $legPrice;

            $stopsData[] = [
                'sequence' => $index + 1,
                'lat' => $stopInput['lat'],
                'lng' => $stopInput['lng'],
                'address' => $stopInput['address'] ?? null,
                'sector_id' => $stopInput['sector_id'] ?? null,
                'leg_distance_km' => $legDistanceKm,
                'leg_price' => $legPrice,
            ];

            $previousLat = (float) $stopInput['lat'];
            $previousLng = (float) $stopInput['lng'];
        }

        $stopsPrice = $stopsData ? PriceCalculator::roundUpToDime($stopsPrice) : null;

        $suggestedPrice = PriceCalculator::suggestedPrice(
            $distanceKm,
            $ratePerKm,
            driverMinimumFare: $this->referenceMinimumFare($driverUserId),
        )['total'];

        // Pedido explícito del usuario: la contraoferta inicial no puede ser
        // menor al precio estimado — evita que alguien proponga un monto
        // simbólico ($2, como se vio en un caso real) para hacerle perder el
        // tiempo al conductor. Se valida contra el estimado que calcula ACÁ
        // el propio servidor (PriceCalculator, con la tarifa mínima y el
        // recargo nocturno ya aplicados), nunca contra lo que haya mostrado
        // el navegador — ese número se puede manipular.
        if (isset($validated['offered_price']) && $validated['offered_price'] < $suggestedPrice) {
            throw ValidationException::withMessages([
                'offered_price' => 'Su propuesta no puede ser menor al precio estimado ($'.number_format($suggestedPrice, 2).').',
            ]);
        }

        $offeredPrice = round((float) ($validated['offered_price'] ?? $suggestedPrice), 2);

        // Auditoría explicable del orden automático. Una selección manual o
        // una carrera programada no pasa por el motor y deja estos campos en
        // null. Si la auditoría falla, la solicitud sigue normalmente.
        $smartDispatchVersion = null;
        $smartDispatchSnapshot = null;
        if ($dispatchPool && $driverUserId && ! $isScheduled && config('smart_dispatch.enabled', true)) {
            try {
                $smartDispatchVersion = SmartDispatchScorer::VERSION;
                $smartDispatchSnapshot = SmartDispatchScorer::safeSnapshot(
                    [$driverUserId, ...$offerCandidateIds],
                    (float) $validated['origin_lat'],
                    (float) $validated['origin_lng'],
                );
            } catch (\Throwable $exception) {
                Log::warning('No se pudo guardar la auditoría del despacho inteligente.', [
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        $rideRequest = DB::transaction(function () use (
            $validated, $fleet, $request, $distanceKm, $offeredPrice, $isScheduled, $scheduledAt,
            $driverUserId, $dispatchPool, $offerCandidateIds, $currentOfferExpiresAt, $needsTrunk, $passengerCount, $requestStatus,
            $cooperative, $cooperativeCandidateIds, $cooperativeOfferExpiresAt, $cooperativeAssignmentStatus,
            $smartDispatchVersion, $smartDispatchSnapshot, $stopsPrice, $stopsData,
        ) {
            $rideRequest = RideRequest::query()->create([
                'fleet_id' => $fleet->id,
                'cooperative_id' => $cooperative?->id,
                'cooperative_assignment_status' => $cooperativeAssignmentStatus,
                'cooperative_candidate_ids' => $cooperativeCandidateIds ?: null,
                'cooperative_offer_expires_at' => $cooperativeOfferExpiresAt,
                'client_user_id' => $request->user()->id,
                'driver_user_id' => $driverUserId,
                'origin_lat' => $validated['origin_lat'],
                'origin_lng' => $validated['origin_lng'],
                'origin_address' => $validated['origin_address'] ?? null,
                'origin_sector_id' => $validated['origin_sector_id'] ?? null,
                'destination_lat' => $validated['destination_lat'],
                'destination_lng' => $validated['destination_lng'],
                'destination_address' => $validated['destination_address'] ?? null,
                'destination_sector_id' => $validated['destination_sector_id'] ?? null,
                'distance_km' => $distanceKm,
                'payment_method' => $validated['payment_method'] ?? 'efectivo',
                'status' => $requestStatus,
                'current_offered_price' => $offeredPrice,
                'stops_price' => $stopsPrice,
                'negotiation_round' => 0,
                'last_offer_made_by' => 'client',
                'requested_at' => now(),
                'is_scheduled' => $isScheduled,
                'scheduled_at' => $scheduledAt,
                'round_trip' => (bool) ($validated['round_trip'] ?? false),
                'dispatch_pool' => $dispatchPool,
                'offer_candidate_ids' => $offerCandidateIds ?: null,
                'current_offer_expires_at' => $currentOfferExpiresAt,
                'smart_dispatch_version' => $smartDispatchVersion,
                'smart_dispatch_snapshot' => $smartDispatchSnapshot,
                'passenger_count' => $passengerCount,
                'needs_trunk' => $needsTrunk,
                'notes' => $validated['notes'] ?? null,
            ]);

            RidePriceOffer::query()->create([
                'ride_request_id' => $rideRequest->id,
                'offered_by_user_id' => $request->user()->id,
                'offered_amount' => $offeredPrice,
            ]);

            // Paradas adicionales (pedido explícito del usuario) — se copian
            // a RideStop cuando un conductor acepte, ver accept() más abajo.
            foreach ($stopsData as $stopData) {
                $rideRequest->stops()->create($stopData);
            }

            return $rideRequest;
        });

        Log::info('Carrera solicitada.', [
            'ride_request_id' => $rideRequest->id,
            'fleet_id' => $fleet->id,
            'cooperative_id' => $cooperative?->id,
            'client_user_id' => $rideRequest->client_user_id,
            'driver_user_id' => $rideRequest->driver_user_id,
            'distance_km' => $distanceKm,
            'offered_price' => $offeredPrice,
        ]);

        if ($rideRequest->cooperative_id) {
            broadcast(new CooperativeRideUpdated($rideRequest, 'created'));
            $rideRequest->cooperative->user->notify(new CooperativeRideRequestedPushNotification($rideRequest));
        }

        if ($rideRequest->status === 'waiting') {
            // Lista de espera (pedido explícito del usuario): todavía no hay
            // a quién avisarle — se revisa sola apenas alguien se desocupe
            // (RideController::complete() → RideDispatchAdvancer::activateNextWaitingRequest())
            // o expira a los 15 min si nadie lo hace a tiempo.
            Log::info('Solicitud quedó en espera: todos los conductores elegibles están ocupados ahora mismo.', [
                'ride_request_id' => $rideRequest->id,
            ]);

            ExpireWaitingRideRequest::dispatch($rideRequest->id)->delay(now()->addMinutes(15));

            return redirect()->route('rides.index');
        }

        // Si todavía no hay una unidad disponible, la solicitud queda en el
        // panel de la cooperativa para asignación manual. No se emite una
        // alerta vacía a conductores ajenos.
        if ($rideRequest->cooperative_id && ! $rideRequest->driver_user_id) {
            if (! $rideRequest->is_scheduled) {
                FallbackCooperativeAssignment::dispatch($rideRequest->id)
                    ->delay(now()->addSeconds((int) ($cooperative->manual_assignment_timeout_seconds ?? 30)));
            }

            return redirect()->route('rides.index');
        }

        broadcast(new RideRequested($rideRequest))->toOthers();
        $this->notifyDriversOfNewRequest($rideRequest, $fleet);
        if ($rideRequest->cooperative_id && $rideRequest->driver_user_id) {
            $rideRequest->client->notify(new CooperativeRideAssignedPushNotification($rideRequest));
        }

        // Despacho secuencial estilo Uber (pedido explícito del usuario): si
        // este candidato no responde en 30 segundos, ExpireRideOffer pasa al
        // siguiente de la bolsa (o expira si ya no queda ninguno). Solo
        // aplica a la bolsa/cooperativa (`dispatch_pool` siempre queda
        // null en una dirigida) — una solicitud dirigida a un conductor
        // puntual usa en cambio el comando periódico
        // rides:expire-overdue-directed-requests (bug encontrado en una
        // auditoría del flujo: dispatch()->delay() bajo QUEUE_CONNECTION=sync
        // corre AL TOQUE, no en 5 minutos — eso está bien para la bolsa
        // (30 seg. reales en producción con una cola de verdad) pero
        // reventaba cualquier solicitud dirigida creada en un test o en un
        // entorno sin worker, expirándola en el mismo request que la crea).
        if ($rideRequest->current_offer_expires_at && $rideRequest->dispatch_pool) {
            ExpireRideOffer::dispatch($rideRequest->id, $rideRequest->driver_user_id)
                ->delay($rideRequest->current_offer_expires_at);
        }

        return redirect()->route('rides.index');
    }

    /**
     * Notificación push de la nueva solicitud (sección 9.2 y 9.5), además del
     * WebSocket: avisa aunque el conductor tenga la app cerrada. Dirigida =
     * solo ese conductor; "toda la flota" = todos sus miembros activos.
     */
    private function notifyDriversOfNewRequest(RideRequest $rideRequest, Fleet $fleet): void
    {
        $driverIds = $rideRequest->driver_user_id
            ? [$rideRequest->driver_user_id]
            // "requests_disabled" (pedido explícito del usuario): un
            // conductor que deshabilitó a este cliente puntual no se entera
            // ni siquiera cuando la solicitud es "a toda la flota".
            : $fleet->activeMembers()->where('requests_disabled', false)->pluck('driver_user_id')->all();

        User::query()->whereIn('id', $driverIds)->with('driverProfile')->get()
            // Zona de cobertura (pedido explícito del usuario): en "toda la
            // flota" no se validó al crear la solicitud (no hay un conductor
            // puntual todavía) — acá es donde de verdad se filtra quién se
            // entera de una "a toda la flota" que le queda lejos. Una
            // dirigida ya pasó la validación de store(), siempre está en rango.
            ->filter(fn (User $driver) => $driver->driverProfile?->isWithinRangeOf(
                (float) $rideRequest->origin_lat,
                (float) $rideRequest->origin_lng,
            ) ?? true)
            ->each(function (User $driver) use ($rideRequest) {
                $driver->notify(new RideRequestedPushNotification($rideRequest));
                // Aviso por WhatsApp (pedido explícito del usuario): aunque el
                // conductor tenga la app cerrada, esto le llega igual —
                // siempre que tenga la ventana de 24h abierta (ver WhatsAppSession).
                WhatsAppFreeformSender::sendNewRideAlert($driver, $rideRequest);
            });
    }

    /**
     * Resuelve a qué flota del cliente se refiere la solicitud (sección 7.3:
     * multi-flota). Si viene un id, se valida que sea suya (findOrFail scopeado
     * por owner_user_id hace de autorización); si no viene, se usa su primera
     * flota, creándosela si todavía no tiene ninguna — mismo comportamiento que
     * existía antes de que el multi-flota fuera posible.
     */
    private function resolveFleet(Request $request, ?int $fleetId): Fleet
    {
        if ($fleetId) {
            return Fleet::query()
                ->where('owner_user_id', $request->user()->id)
                ->findOrFail($fleetId);
        }

        return Fleet::query()
            ->where('owner_user_id', $request->user()->id)
            ->orderBy('id')
            ->first()
            ?? Fleet::query()->create([
                'owner_user_id' => $request->user()->id,
                'name' => 'Mi flota',
            ]);
    }

    /**
     * Tarifa de referencia para calcular el precio sugerido inicial (sección 5).
     * Si la solicitud es dirigida, se usa la tarifa real de ese conductor; si es
     * "a toda la flota", no hay un conductor todavía, así que se usa el promedio
     * de la flota activa como anchor — cada conductor puede seguir contraofertando
     * con su propio número si no le alcanza (documentado: simplificación explícita,
     * no hay un precio por-conductor distinto antes de que alguien responda).
     */
    private function referenceRatePerKm(Fleet $fleet, ?int $driverUserId): float
    {
        if ($driverUserId) {
            return (float) (User::find($driverUserId)?->driverProfile?->rate_per_km ?? 0);
        }

        $rates = $fleet->activeMembers()
            ->with('driver.driverProfile')
            ->get()
            ->map(fn ($member) => (float) ($member->driver->driverProfile?->rate_per_km ?? 0))
            ->filter();

        return $rates->isEmpty() ? 0.0 : round($rates->avg(), 2);
    }

    /**
     * Tarifa mínima propia de ESE conductor (pedido explícito del usuario),
     * si la declaró en su perfil — null si es "a toda la flota" (todavía no
     * hay un conductor puntual) o si no declaró ninguna, y ahí
     * PriceCalculator usa directo la de la plataforma. Nunca se promedia
     * entre conductores: es una excepción individual, no un dato de flota.
     */
    private function referenceMinimumFare(?int $driverUserId): ?float
    {
        if (! $driverUserId) {
            return null;
        }

        $minimumFare = User::find($driverUserId)?->driverProfile?->minimum_fare;

        return $minimumFare !== null ? (float) $minimumFare : null;
    }

    /**
     * El conductor acepta el precio vigente (sección 3.5 y 5). Si nadie había
     * contraofertado todavía, cualquier conductor elegible puede aceptar (con
     * lock de fila para que, en "toda la flota", solo el primero se la quede).
     * Si el conductor ya había contraofertado, quien acepta acá es el CLIENTE
     * — está aceptando el número que le propuso el conductor.
     */
    public function accept(Request $request, RideRequest $rideRequest): RedirectResponse
    {
        $userId = $request->user()->id;

        if ($rideRequest->status === 'negotiating') {
            if ($rideRequest->client_user_id !== $userId) {
                abort(403);
            }
            $driverId = $rideRequest->negotiating_driver_user_id;
        } else {
            if ($rideRequest->isDirected() && $rideRequest->driver_user_id !== $userId) {
                abort(403);
            }

            if (! $rideRequest->isDirected()) {
                $isActiveMember = $rideRequest->fleet->activeMembers()
                    ->where('driver_user_id', $userId)
                    ->exists();

                if (! $isActiveMember) {
                    abort(403);
                }
            }

            $driverId = $userId;
        }

        $ride = DB::transaction(function () use ($rideRequest, $driverId) {
            // Bloqueamos la fila para que una segunda aceptación simultánea
            // (carrera "toda la flota") no pise a la primera.
            $locked = RideRequest::query()->lockForUpdate()->findOrFail($rideRequest->id);

            if (! in_array($locked->status, ['pending', 'negotiating'], true)) {
                throw ValidationException::withMessages([
                    'ride_request' => 'Esta solicitud ya no está disponible.',
                ]);
            }

            // Serializa las aceptaciones del mismo conductor. Aunque dos
            // solicitudes distintas se acepten casi al mismo tiempo, solo
            // una puede convertirlo en conductor de una carrera inmediata.
            User::query()->lockForUpdate()->findOrFail($driverId);

            if (! $locked->is_scheduled && Ride::query()
                ->where('driver_user_id', $driverId)
                ->where('status', 'in_progress')
                ->exists()) {
                throw ValidationException::withMessages([
                    'ride_request' => 'Ya tiene una carrera en curso. Termine o cancele ese viaje antes de aceptar otro.',
                ]);
            }

            $ratePerKm = User::find($driverId)?->driverProfile?->rate_per_km ?? 0;

            $ride = Ride::query()->create([
                'ride_request_id' => $locked->id,
                'fleet_id' => $locked->fleet_id,
                'client_user_id' => $locked->client_user_id,
                'driver_user_id' => $driverId,
                'origin_lat' => $locked->origin_lat,
                'origin_lng' => $locked->origin_lng,
                'origin_address' => $locked->origin_address,
                'origin_sector_id' => $locked->origin_sector_id,
                'destination_lat' => $locked->destination_lat,
                'destination_lng' => $locked->destination_lng,
                'destination_address' => $locked->destination_address,
                'destination_sector_id' => $locked->destination_sector_id,
                'distance_km' => $locked->distance_km,
                'payment_method' => $locked->payment_method,
                'notes' => $locked->notes,
                'round_trip' => $locked->round_trip,
                'rate_per_km_snapshot' => $ratePerKm,
                // El precio final es el que quedó vigente en la negociación
                // (sección 5), no se recalcula — puede ser el sugerido tal
                // cual o el número en el que las partes terminaron de acuerdo.
                'price' => $locked->current_offered_price,
                // Paradas adicionales (pedido explícito del usuario) — la
                // suma de sus tramos, ya calculada al pedir la carrera.
                'stops_price' => $locked->stops_price,
                // Una solicitud PROGRAMADA (consideración agregada al alcance)
                // no puede arrancar "en curso" de una: el conductor quedaría
                // "ocupado" desde que acepta hasta la hora programada, que
                // puede ser horas o días después. Queda en 'scheduled' (sin
                // `started_at` todavía) hasta que el conductor la arranque de
                // verdad — ver RideController::start().
                'status' => $locked->is_scheduled ? 'scheduled' : 'in_progress',
                'started_at' => $locked->is_scheduled ? null : now(),
            ]);

            // Copia las paradas de la solicitud a la carrera ya aceptada,
            // cada una arrancando "pending" — ver RideController::completeStop().
            foreach ($locked->stops as $stop) {
                $ride->stops()->create([
                    'sequence' => $stop->sequence,
                    'lat' => $stop->lat,
                    'lng' => $stop->lng,
                    'address' => $stop->address,
                    'sector_id' => $stop->sector_id,
                    'leg_distance_km' => $stop->leg_distance_km,
                    'leg_price' => $stop->leg_price,
                    'status' => 'pending',
                ]);
            }

            $locked->update([
                'status' => 'accepted',
                'accepted_by' => $driverId,
                'responded_at' => now(),
                'cooperative_assignment_status' => $locked->cooperative_id ? 'accepted' : $locked->cooperative_assignment_status,
                'cooperative_offer_expires_at' => null,
            ]);

            return $ride;
        });

        Log::info('Carrera aceptada.', [
            'ride_request_id' => $rideRequest->id,
            'ride_id' => $ride->id,
            'driver_user_id' => $driverId,
        ]);

        broadcast(new RideRequestAccepted($rideRequest->fresh(), $ride->id))->toOthers();

        // Aviso push al cliente (pedido explícito del usuario: "cuando el
        // conductor sale a buscar al cliente, avisarle") — cubre el caso de
        // que tenga la app cerrada, a diferencia del WebSocket de arriba.
        $ride->client->notify(new RideAcceptedPushNotification($ride));
        WhatsAppFreeformSender::sendRideAcceptedToClient($ride);

        return redirect()->route('rides.show', $ride);
    }

    /**
     * El conductor contraoferta un precio distinto al sugerido (sección 5),
     * su única ronda permitida. A partir de acá el cliente solo puede aceptar
     * ese número o rechazarlo — no hay más rondas, para no alargar el proceso.
     */
    public function counter(Request $request, RideRequest $rideRequest): RedirectResponse
    {
        $userId = $request->user()->id;

        if ($rideRequest->isDirected() && $rideRequest->driver_user_id !== $userId) {
            abort(403);
        }

        if (! $rideRequest->isDirected()) {
            $isActiveMember = $rideRequest->fleet->activeMembers()
                ->where('driver_user_id', $userId)
                ->exists();

            if (! $isActiveMember) {
                abort(403);
            }
        }

        $validated = $request->validate([
            'offered_amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        DB::transaction(function () use ($rideRequest, $userId, $validated) {
            $locked = RideRequest::query()->lockForUpdate()->findOrFail($rideRequest->id);

            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages([
                    'ride_request' => 'Esta solicitud ya no está disponible para contraofertar.',
                ]);
            }

            $locked->update([
                'status' => 'negotiating',
                'current_offered_price' => round($validated['offered_amount'], 2),
                'negotiation_round' => 1,
                'last_offer_made_by' => 'driver',
                'negotiating_driver_user_id' => $userId,
            ]);

            RidePriceOffer::query()->create([
                'ride_request_id' => $locked->id,
                'offered_by_user_id' => $userId,
                'offered_amount' => round($validated['offered_amount'], 2),
            ]);
        });

        Log::info('Conductor contraofertó.', [
            'ride_request_id' => $rideRequest->id,
            'driver_user_id' => $userId,
            'offered_amount' => round($validated['offered_amount'], 2),
        ]);

        $updatedRequest = $rideRequest->fresh(['client', 'negotiatingDriver']);

        broadcast(new RideRequestCountered($updatedRequest))->toOthers();
        $updatedRequest->client->notify(new RideRequestCounteredPushNotification(
            $updatedRequest->id,
            $updatedRequest->negotiatingDriver->name,
            (float) $updatedRequest->current_offered_price,
        ));

        return back();
    }

    /**
     * El cliente sube su propia oferta mientras nadie respondió todavía
     * (feedback tipo "si no te aceptan, subí la tarifa para que sea más
     * atractiva", sección 5 y 9.7). Solo tiene sentido en estado "pending"
     * — una vez que un conductor contraofertó (negotiating), es el cliente
     * quien tiene que responder a esa propuesta, no al revés.
     */
    public function raiseOffer(Request $request, RideRequest $rideRequest): RedirectResponse
    {
        if ($rideRequest->client_user_id !== $request->user()->id) {
            abort(403);
        }

        $validated = $request->validate([
            'offered_amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        DB::transaction(function () use ($rideRequest, $request, $validated) {
            $locked = RideRequest::query()->lockForUpdate()->findOrFail($rideRequest->id);

            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages([
                    'ride_request' => 'Esta solicitud ya no está pendiente.',
                ]);
            }

            if ((float) $validated['offered_amount'] <= (float) $locked->current_offered_price) {
                throw ValidationException::withMessages([
                    'offered_amount' => 'El nuevo monto tiene que ser mayor al que ya ofreció.',
                ]);
            }

            $locked->update(['current_offered_price' => round($validated['offered_amount'], 2)]);

            RidePriceOffer::query()->create([
                'ride_request_id' => $locked->id,
                'offered_by_user_id' => $request->user()->id,
                'offered_amount' => round($validated['offered_amount'], 2),
            ]);
        });

        Log::info('Cliente subió su oferta.', [
            'ride_request_id' => $rideRequest->id,
            'client_user_id' => $request->user()->id,
            'offered_amount' => round($validated['offered_amount'], 2),
        ]);

        broadcast(new RideRequestPriceRaised($rideRequest->fresh()))->toOthers();

        return back();
    }

    /**
     * El conductor rechaza (solo tiene sentido cuando la solicitud fue dirigida
     * a él puntualmente; en modo "toda la flota" simplemente no responde).
     */
    public function reject(Request $request, RideRequest $rideRequest): RedirectResponse
    {
        if ($rideRequest->driver_user_id !== $request->user()->id) {
            abort(403);
        }

        if ($rideRequest->status !== 'pending') {
            throw ValidationException::withMessages([
                'ride_request' => 'Esta solicitud ya no está pendiente.',
            ]);
        }

        Log::info('Conductor rechazó la solicitud.', [
            'ride_request_id' => $rideRequest->id,
            'driver_user_id' => $request->user()->id,
        ]);

        // Bitácora de rechazos por conductor (pedido explícito del usuario):
        // un rechazo explícito (este endpoint) cuenta — un simple timeout de
        // los 30 segundos NO, porque no necesariamente significa que lo vio
        // y dijo que no, puede ser que no tenía la app abierta.
        $request->user()->driverProfile?->increment('rides_rejected_count');

        // Despacho secuencial estilo Uber (pedido explícito del usuario): un
        // rechazo explícito de este candidato pasa la solicitud al siguiente
        // de la bolsa de inmediato, sin esperar a que se cumplan los 30
        // segundos. Si la solicitud era dirigida a este conductor puntual
        // (dispatch_pool null), se mantiene el cancelado de siempre.
        if ($rideRequest->isSequentialDispatch()) {
            RideDispatchAdvancer::advanceOrExpire($rideRequest->id, $request->user()->id, 'rejected');

            return back();
        }

        $driverName = $request->user()->name;

        $rideRequest->update(['status' => 'cancelled', 'responded_at' => now()]);

        // Avisa a otras sesiones de ESTE conductor (ej. otro dispositivo con
        // sesión abierta) — no le sirve al cliente, ver RideRequestDeclined.
        broadcast(new RideRequestCancelled($rideRequest))->toOthers();

        // Bug reportado por el usuario: el cliente pidió la carrera a este
        // conductor puntual, lo rechazó, y no había ningún aviso — la
        // tarjeta "Esperando respuesta" se quedaba pegada en silencio para
        // siempre. Acá sí hace falta avisarle al CLIENTE, y recomendarle
        // ampliar la búsqueda (a toda su flota o al directorio público).
        broadcast(new RideRequestDeclined($rideRequest, $driverName));
        $rideRequest->client->notify(new RideRequestDeclinedPushNotification($rideRequest, $driverName));

        return back();
    }

    /**
     * El cliente cancela una solicitud que todavía no fue aceptada — ya sea
     * porque nadie respondió, o porque no le convenció la contraoferta del
     * conductor.
     */
    public function cancel(Request $request, RideRequest $rideRequest): RedirectResponse
    {
        if ($rideRequest->client_user_id !== $request->user()->id) {
            abort(403);
        }

        // 'waiting' (pedido explícito del usuario): también se puede
        // cancelar una solicitud en espera, antes de que alguien se
        // desocupe o de que expire sola a los 15 min.
        if (! in_array($rideRequest->status, ['pending', 'negotiating', 'waiting'], true)) {
            throw ValidationException::withMessages([
                'ride_request' => 'Esta solicitud ya no está pendiente.',
            ]);
        }

        $rideRequest->update(['status' => 'cancelled', 'responded_at' => now()]);

        Log::info('Cliente canceló la solicitud.', [
            'ride_request_id' => $rideRequest->id,
            'client_user_id' => $request->user()->id,
        ]);

        broadcast(new RideRequestCancelled($rideRequest))->toOthers();

        return back();
    }
}
