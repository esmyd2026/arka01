<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\Cooperative;
use App\Models\DriverProfile;
use App\Models\DriverTier;
use App\Models\Fleet;
use App\Models\PricingSetting;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\User;
use App\Services\FrequentPlaces;
use App\Services\Haversine;
use App\Services\PlanLimits;
use App\Services\Ride\RideRequestCreator;
use App\Services\Ride\RideRequestResponder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RideRequestController extends Controller
{
    /** Pedido explícito del usuario: "pedir una carrera" es del lado cliente. */
    private const SINGLE_ROLE_MESSAGE = 'Los conductores no pueden pedir una carrera — cada cuenta es cliente o conductor, no ambas.';

    public function __construct(
        private readonly PlanLimits $planLimits,
        private readonly RideRequestCreator $rideRequestCreator,
        private readonly RideRequestResponder $rideRequestResponder,
    ) {}

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

        $fleet = $this->rideRequestCreator->resolveFleet($request->user(), $request->query('flota') ? (int) $request->query('flota') : null);
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
            // Cargo por trayecto de recogida (pedido explícito del usuario):
            // al cliente solo se le muestra el % configurado, como aviso
            // informativo — nunca el monto, que depende de qué conductor le
            // toque (ver App\Services\PriceCalculator::pickupSurcharge()).
            'pickupSurchargePercent' => (int) PricingSetting::current()->pickup_surcharge_percent,
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
            'public_category' => $profile?->public_category,
            'public_category_label' => $profile?->visiblePublicCategoryLabel(),
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
     * una contraoferta propia como primer movimiento. Toda la lógica real vive
     * en App\Services\Ride\RideRequestCreator (roadmap app móvil, Hito 5) —
     * este método solo autoriza y valida, para que web y móvil compartan
     * exactamente la misma regla de negocio.
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

        $validated = $request->validate(RideRequestCreator::rules());

        $this->rideRequestCreator->create($request->user(), $validated);

        return redirect()->route('rides.index');
    }

    /**
     * El conductor acepta el precio vigente (sección 3.5 y 5). Si nadie había
     * contraofertado todavía, cualquier conductor elegible puede aceptar (con
     * lock de fila para que, en "toda la flota", solo el primero se la quede).
     * Si el conductor ya había contraofertado, quien acepta acá es el CLIENTE
     * — está aceptando el número que le propuso el conductor. Lógica real en
     * App\Services\Ride\RideRequestResponder.
     */
    public function accept(Request $request, RideRequest $rideRequest): RedirectResponse
    {
        // Cargo por trayecto de recogida (pedido explícito del usuario): el
        // conductor decide cobrarlo o no al momento de aceptar, ver el
        // checkbox en Ride/Index.vue.
        $ride = $this->rideRequestResponder->accept($rideRequest, $request->user(), $request->boolean('charge_pickup_fee'));

        return redirect()->route('rides.show', $ride);
    }

    /**
     * El conductor contraoferta un precio distinto al sugerido (sección 5),
     * su única ronda permitida. A partir de acá el cliente solo puede aceptar
     * ese número o rechazarlo — no hay más rondas, para no alargar el proceso.
     */
    public function counter(Request $request, RideRequest $rideRequest): RedirectResponse
    {
        $validated = $request->validate([
            'offered_amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $this->rideRequestResponder->counter($rideRequest, $request->user(), (float) $validated['offered_amount']);

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
        $validated = $request->validate([
            'offered_amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $this->rideRequestResponder->raiseOffer($rideRequest, $request->user(), (float) $validated['offered_amount']);

        return back();
    }

    /**
     * El conductor rechaza (solo tiene sentido cuando la solicitud fue dirigida
     * a él puntualmente; en modo "toda la flota" simplemente no responde).
     */
    public function reject(Request $request, RideRequest $rideRequest): RedirectResponse
    {
        $this->rideRequestResponder->reject($rideRequest, $request->user());

        return back();
    }

    /**
     * El cliente cancela una solicitud que todavía no fue aceptada — ya sea
     * porque nadie respondió, o porque no le convenció la contraoferta del
     * conductor.
     */
    public function cancel(Request $request, RideRequest $rideRequest): RedirectResponse
    {
        $this->rideRequestResponder->cancel($rideRequest, $request->user());

        return back();
    }
}
