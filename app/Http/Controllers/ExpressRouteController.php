<?php

namespace App\Http\Controllers;

use App\Models\DriverProfile;
use App\Models\ExpressRoute;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\PricingSetting;
use App\Services\Haversine;
use App\Services\PlanLimits;
use App\Services\PriceCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Expresos" (sección 4): rutas fijas y recurrentes que un cliente publica
 * con horario, condiciones y precio pactados de antemano. Los conductores se
 * postulan (App\Http\Controllers\ExpressApplicationController) y, al aceptar
 * uno, el sistema genera la solicitud de carrera automáticamente cada día que
 * corresponda (App\Console\Commands\GenerateExpressRides).
 */
class ExpressRouteController extends Controller
{
    /** Pedido explícito del usuario: "publicar un Expreso" es del lado cliente, el conductor no solicita servicios. */
    private const SINGLE_ROLE_MESSAGE = 'Los conductores no pueden publicar Expresos — cada cuenta es cliente o conductor, no ambas.';

    /**
     * Pedido explícito del usuario: el precio NO tiene que calzar con el
     * estimado, pero tampoco puede irse tan bajo que deje de convenirle a
     * cualquier conductor — 50% del estimado como piso real.
     */
    private const MINIMUM_PRICE_FACTOR = 0.5;

    public function __construct(private readonly PlanLimits $planLimits) {}

    /**
     * Mis Expresos (lado cliente): los que publiqué, con su estado y
     * postulaciones pendientes de revisar.
     */
    public function index(Request $request): Response|RedirectResponse
    {
        // Bug reportado por el usuario ("el conductor no solicita servicios,
        // recordá eso"): mismo criterio que RideRequestController::create().
        if ($request->user()->isDriver()) {
            return redirect()->route('dashboard')->with('status', self::SINGLE_ROLE_MESSAGE);
        }

        $routes = ExpressRoute::query()
            ->where('client_user_id', $request->user()->id)
            ->withCount(['applications as pending_applications_count' => fn ($query) => $query->where('status', 'pending')])
            ->with('assignedDriver')
            ->latest()
            ->get();

        $clientCity = $request->user()->city;

        return Inertia::render('Express/Index', [
            'routes' => $routes,
            // Pedido explícito del usuario: sugerir un costo aproximado antes
            // de fijar el precio por carrera — misma tarifa de referencia que
            // ya usa "Pedir carrera" para toda la flota (promedio de los
            // conductores activos), acá sobre TODAS sus flotas porque un
            // Expreso no se publica para una en particular.
            'referenceRatePerKm' => $this->referenceRatePerKm($request->user()->id),
            'minimumFare' => (float) PricingSetting::current()->minimum_fare,
            // Pedido explícito del usuario: no tiene que calzar con el
            // estimado, pero tampoco puede irse por debajo de esta fracción —
            // una sola fuente de verdad con lo que valida store()/update().
            'minimumPriceFactor' => self::MINIMUM_PRICE_FACTOR,
            // Pedido explícito del usuario: el mapa arrancaba siempre en Quito
            // por defecto — con esto, si el navegador no da geolocalización
            // (o el cliente no responde el permiso a tiempo), al menos centra
            // en la ciudad que ya tiene registrada.
            'clientCity' => $clientCity ? ['lat' => (float) $clientCity->lat, 'lng' => (float) $clientCity->lng] : null,
        ]);
    }

    /**
     * Promedio de tarifa/km de los conductores activos en cualquiera de las
     * flotas del cliente (un Expreso lo ven todos, no una flota puntual —
     * ver ExpressRouteController::available()). Bug reportado por el usuario
     * ("sale $0, deberías sacar un estimado"): recién publicando su primer
     * Expreso, todavía no tiene NINGÚN conductor en su flota — sin fallback,
     * el estimado quedaba siempre en $0 para cualquier cliente nuevo, que es
     * justo el caso más común. Si su propia flota no tiene con qué calcular
     * un promedio, se usa el promedio de TODA la plataforma como referencia.
     */
    private function referenceRatePerKm(int $clientUserId): float
    {
        $fleetIds = Fleet::query()->where('owner_user_id', $clientUserId)->pluck('id');

        $rates = FleetMember::query()
            ->whereIn('fleet_id', $fleetIds)
            ->whereNull('left_at')
            ->with('driver.driverProfile')
            ->get()
            ->map(fn ($member) => (float) ($member->driver->driverProfile?->rate_per_km ?? 0))
            ->filter();

        if ($rates->isNotEmpty()) {
            return round($rates->avg(), 2);
        }

        $platformAverage = DriverProfile::query()->where('rate_per_km', '>', 0)->avg('rate_per_km');

        return $platformAverage ? round((float) $platformAverage, 2) : 0.0;
    }

    /**
     * Piso de precio (pedido explícito del usuario: "el precio que coloque
     * que no sea menor al estimado") — mismo motor que "Pedir carrera"
     * (PriceCalculator, con la tarifa mínima ya aplicada), pero sin el
     * recargo nocturno: acá el precio se pacta una sola vez para TODOS los
     * días que corresponda, no tiene sentido atarlo al horario de cuando se
     * publica el Expreso.
     */
    private function suggestedPrice(float $originLat, float $originLng, float $destinationLat, float $destinationLng, float $ratePerKm): float
    {
        $distanceKm = Haversine::distanceKm($originLat, $originLng, $destinationLat, $destinationLng);

        return PriceCalculator::suggestedPrice($distanceKm, $ratePerKm)['base'];
    }

    /**
     * Publica un Expreso nuevo, con sus condiciones pactadas (sección 4.1).
     */
    public function store(Request $request): RedirectResponse
    {
        // Defensa en profundidad: el nav ya oculta "Mis Expresos" a un
        // conductor (clientOnly en AuthenticatedLayout.vue), pero eso no
        // frena un POST directo a esta URL.
        if ($request->user()->isDriver()) {
            throw ValidationException::withMessages(['name' => self::SINGLE_ROLE_MESSAGE]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'origin_lat' => ['required', 'numeric', 'between:-90,90'],
            'origin_lng' => ['required', 'numeric', 'between:-180,180'],
            'origin_address' => ['nullable', 'string', 'max:255'],
            'destination_lat' => ['required', 'numeric', 'between:-90,90'],
            'destination_lng' => ['required', 'numeric', 'between:-180,180'],
            'destination_address' => ['nullable', 'string', 'max:255'],
            'days_of_week' => ['required', 'array', 'min:1'],
            'days_of_week.*' => ['integer', 'between:0,6'],
            'departure_time' => ['required', 'date_format:H:i'],
            // Ida y vuelta (pedido explícito del usuario): además de la hora
            // de salida DEL origen, hace falta la hora de salida DEL destino
            // — GenerateExpressRides genera una segunda carrera ese horario.
            'is_round_trip' => ['boolean'],
            'return_time' => ['nullable', 'required_if:is_round_trip,true', 'date_format:H:i'],
            'offered_price' => ['required', 'numeric', 'min:0.01'],
            'conditions' => ['nullable', 'array'],
            'conditions.*' => ['string', 'max:150'],
            // Pedido explícito del usuario: el dueño decide si abre su
            // Expreso a que otros con ruta parecida se sumen y repartan el
            // costo — no es automático.
            'share_enabled' => ['boolean'],
            'max_companions' => ['nullable', 'integer', 'min:1', 'max:6'],
        ]);

        // Pedido explícito del usuario: no tiene que calzar con el estimado,
        // pero tampoco puede irse por debajo del 50% de ese estimado — se
        // valida acá, con lo que calcula el propio servidor, nunca contra lo
        // que haya mostrado el navegador.
        $suggestedPrice = $this->suggestedPrice(
            $validated['origin_lat'],
            $validated['origin_lng'],
            $validated['destination_lat'],
            $validated['destination_lng'],
            $this->referenceRatePerKm($request->user()->id),
        );
        $minimumPrice = round($suggestedPrice * self::MINIMUM_PRICE_FACTOR, 2);

        if ($validated['offered_price'] < $minimumPrice) {
            throw ValidationException::withMessages([
                'offered_price' => 'El precio no puede ser menor a $'.number_format($minimumPrice, 2).' (mitad del estimado de $'.number_format($suggestedPrice, 2).').',
            ]);
        }

        $route = ExpressRoute::query()->create([
            'client_user_id' => $request->user()->id,
            'name' => $validated['name'],
            'origin_lat' => $validated['origin_lat'],
            'origin_lng' => $validated['origin_lng'],
            'origin_address' => $validated['origin_address'] ?? null,
            'destination_lat' => $validated['destination_lat'],
            'destination_lng' => $validated['destination_lng'],
            'destination_address' => $validated['destination_address'] ?? null,
            'days_of_week' => array_values(array_unique($validated['days_of_week'])),
            'departure_time' => $validated['departure_time'],
            'is_round_trip' => $validated['is_round_trip'] ?? false,
            'return_time' => ($validated['is_round_trip'] ?? false) ? $validated['return_time'] : null,
            'offered_price' => $validated['offered_price'],
            'status' => 'open',
            'share_enabled' => $validated['share_enabled'] ?? false,
            'max_companions' => $validated['max_companions'] ?? null,
        ]);

        foreach ($validated['conditions'] ?? [] as $description) {
            $route->conditions()->create(['description' => $description]);
        }

        return redirect()->route('express-routes.show', $route);
    }

    /**
     * Detalle de un Expreso: condiciones, postulaciones, conductor asignado
     * (si ya tiene uno) e historial de carreras generadas día a día.
     */
    public function show(Request $request, ExpressRoute $route): Response
    {
        $this->authorize('view', $route);

        $route->load([
            'conditions',
            'assignedDriver',
            'applications' => fn ($query) => $query->with('driver')->latest(),
            'rideRequests' => fn ($query) => $query->with('ride')->latest(),
            // Compartir el Expreso (pedido explícito del usuario): quién más
            // se sumó (o pidió sumarse) a esta ruta.
            'companions' => fn ($query) => $query->with('passenger')->latest(),
        ]);

        $route->price_per_person = $route->pricePerPerson();

        return Inertia::render('Express/Show', [
            // Nombrado "expressRoute" y no "route" a propósito: en el
            // frontend, route() es el helper global de Ziggy para generar
            // URLs — llamar a la prop igual lo taparía dentro del componente.
            'expressRoute' => $route,
            'isOwner' => $request->user()->id === $route->client_user_id,
            'myApplication' => $route->applications->firstWhere('driver_user_id', $request->user()->id),
            'myCompanionRequest' => $route->companions->firstWhere('passenger_user_id', $request->user()->id),
        ]);
    }

    /**
     * Edita los datos de un Expreso, solo mientras está abierto a
     * postulaciones — una vez activo (con conductor asignado), el contrato
     * ya está pactado y no se puede cambiar por debajo sin renegociarlo.
     */
    public function update(Request $request, ExpressRoute $route): RedirectResponse
    {
        $this->authorize('update', $route);

        if (! $route->isOpenForApplications()) {
            throw ValidationException::withMessages([
                'route' => 'Este Expreso ya no está abierto a postulaciones, no se puede editar.',
            ]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'departure_time' => ['required', 'date_format:H:i'],
            'is_round_trip' => ['boolean'],
            'return_time' => ['nullable', 'required_if:is_round_trip,true', 'date_format:H:i'],
            'days_of_week' => ['required', 'array', 'min:1'],
            'days_of_week.*' => ['integer', 'between:0,6'],
            'offered_price' => ['required', 'numeric', 'min:0.01'],
            'share_enabled' => ['boolean'],
            'max_companions' => ['nullable', 'integer', 'min:1', 'max:6'],
        ]);

        // Origen y destino no se editan acá (sin mapa en esta pantalla) — se
        // usan los que ya tiene guardados el Expreso para el mismo piso de
        // precio que rige al publicarlo.
        $suggestedPrice = $this->suggestedPrice(
            (float) $route->origin_lat,
            (float) $route->origin_lng,
            (float) $route->destination_lat,
            (float) $route->destination_lng,
            $this->referenceRatePerKm($route->client_user_id),
        );
        $minimumPrice = round($suggestedPrice * self::MINIMUM_PRICE_FACTOR, 2);

        if ($validated['offered_price'] < $minimumPrice) {
            throw ValidationException::withMessages([
                'offered_price' => 'El precio no puede ser menor a $'.number_format($minimumPrice, 2).' (mitad del estimado de $'.number_format($suggestedPrice, 2).').',
            ]);
        }

        $route->update([
            'name' => $validated['name'],
            'departure_time' => $validated['departure_time'],
            'is_round_trip' => $validated['is_round_trip'] ?? false,
            'return_time' => ($validated['is_round_trip'] ?? false) ? $validated['return_time'] : null,
            'days_of_week' => array_values(array_unique($validated['days_of_week'])),
            'offered_price' => $validated['offered_price'],
            'share_enabled' => $validated['share_enabled'] ?? false,
            'max_companions' => $validated['max_companions'] ?? null,
        ]);

        return back()->with('status', 'Expreso actualizado.');
    }

    /**
     * El cliente pausa un Expreso activo (deja de generar carreras) sin
     * cancelar el contrato del todo — puede reanudarlo más adelante.
     */
    public function pause(Request $request, ExpressRoute $route): RedirectResponse
    {
        $this->authorize('update', $route);

        $route->update(['status' => 'paused']);

        return back()->with('status', 'Expreso pausado.');
    }

    public function resume(Request $request, ExpressRoute $route): RedirectResponse
    {
        $this->authorize('update', $route);

        if (! $route->assigned_driver_user_id) {
            throw ValidationException::withMessages([
                'route' => 'Todavía no tiene un conductor asignado.',
            ]);
        }

        $route->update(['status' => 'active']);

        return back()->with('status', 'Expreso reanudado.');
    }

    /**
     * Cancela el Expreso definitivamente. No borra el historial de carreras
     * ya generadas (sección 9.6: trazabilidad), solo corta la recurrencia.
     */
    public function cancel(Request $request, ExpressRoute $route): RedirectResponse
    {
        $this->authorize('update', $route);

        $route->update(['status' => 'cancelled']);

        return back()->with('status', 'Expreso cancelado.');
    }

    /**
     * Ofertas de Expreso abiertas para un conductor (sección 4.2): las que
     * publicaron clientes de flotas a las que pertenece. Mismo criterio de
     * alcance que el resto de la plataforma — no es un directorio global.
     */
    public function available(Request $request): Response
    {
        $clientIds = Fleet::query()
            ->whereHas('activeMembers', fn ($query) => $query->where('driver_user_id', $request->user()->id))
            ->pluck('owner_user_id');

        $routes = ExpressRoute::query()
            ->where('status', 'open')
            ->whereIn('client_user_id', $clientIds)
            ->with(['client', 'conditions'])
            ->withCount('applications')
            ->latest()
            ->get();

        $myApplications = $request->user()->expressApplications()->pluck('status', 'express_route_id');

        return Inertia::render('Express/Available', [
            'routes' => $routes,
            'myApplications' => $myApplications,
            // Pedido explícito del usuario ("no sé a quién le aparece un
            // Expreso"): con esto la pantalla puede explicar POR QUÉ está
            // vacía, en vez de un genérico "no hay nada" — no es lo mismo no
            // pertenecer a ninguna flota que pertenecer a una sin Expresos
            // abiertos ahora mismo.
            'myFleetCount' => $clientIds->count(),
            'canApply' => $this->planLimits->forDriver($request->user())['express_enabled'],
        ]);
    }
}
