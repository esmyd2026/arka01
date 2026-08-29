<?php

namespace App\Http\Controllers;

use App\Models\ExpressRoute;
use App\Services\Express\ExpressRouteFinder;
use App\Services\Express\ExpressRoutePublisher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Expresos" (sección 4): rutas fijas y recurrentes que un cliente publica
 * con horario, condiciones y precio pactados de antemano. Los conductores se
 * postulan (App\Http\Controllers\ExpressApplicationController) y, al aceptar
 * uno, el sistema genera la solicitud de carrera automáticamente cada día que
 * corresponda (App\Console\Commands\GenerateExpressRides).
 *
 * Lógica real en App\Services\Express\ExpressRoutePublisher/ExpressRouteFinder
 * (roadmap app móvil, "full backend": nunca duplicar una regla entre web y
 * móvil).
 */
class ExpressRouteController extends Controller
{
    public function __construct(
        private readonly ExpressRouteFinder $expressRouteFinder,
        private readonly ExpressRoutePublisher $expressRoutePublisher,
    ) {}

    /**
     * Mis Expresos (lado cliente): los que publiqué, con su estado y
     * postulaciones pendientes de revisar.
     */
    public function index(Request $request): Response|RedirectResponse
    {
        // Bug reportado por el usuario ("el conductor no solicita servicios,
        // recordá eso"): mismo criterio que RideRequestController::create().
        if ($request->user()->isDriver()) {
            return redirect()->route('dashboard')->with('status', ExpressRoutePublisher::SINGLE_ROLE_MESSAGE);
        }

        $clientCity = $request->user()->city;

        return Inertia::render('Express/Index', [
            'routes' => $this->expressRouteFinder->mine($request->user()),
            // Pedido explícito del usuario: sugerir un costo aproximado antes
            // de fijar el precio por carrera — misma tarifa de referencia que
            // ya usa "Pedir carrera" para toda la flota (promedio de los
            // conductores activos), acá sobre TODAS sus flotas porque un
            // Expreso no se publica para una en particular.
            'referenceRatePerKm' => $this->expressRoutePublisher->referenceRatePerKm($request->user()->id),
            'minimumFare' => $this->expressRoutePublisher->minimumFare(),
            // Pedido explícito del usuario: no tiene que calzar con el
            // estimado, pero tampoco puede irse por debajo de esta fracción —
            // una sola fuente de verdad con lo que valida store()/update().
            'minimumPriceFactor' => ExpressRoutePublisher::MINIMUM_PRICE_FACTOR,
            // Pedido explícito del usuario: el mapa arrancaba siempre en Quito
            // por defecto — con esto, si el navegador no da geolocalización
            // (o el cliente no responde el permiso a tiempo), al menos centra
            // en la ciudad que ya tiene registrada.
            'clientCity' => $clientCity ? ['lat' => (float) $clientCity->lat, 'lng' => (float) $clientCity->lng] : null,
        ]);
    }

    /**
     * Publica un Expreso nuevo, con sus condiciones pactadas (sección 4.1).
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(ExpressRoutePublisher::rules());

        // Defensa en profundidad: el nav ya oculta "Mis Expresos" a un
        // conductor (clientOnly en AuthenticatedLayout.vue), pero eso no
        // frena un POST directo a esta URL — ExpressRoutePublisher::create()
        // vuelve a chequear el rol.
        $route = $this->expressRoutePublisher->create($request->user(), $validated);

        return redirect()->route('express-routes.show', $route);
    }

    /**
     * Detalle de un Expreso: condiciones, postulaciones, conductor asignado
     * (si ya tiene uno) e historial de carreras generadas día a día.
     */
    public function show(Request $request, ExpressRoute $route): Response
    {
        $this->authorize('view', $route);

        $route = $this->expressRouteFinder->detail($route);

        return Inertia::render('Express/Show', [
            // Nombrado "expressRoute" y no "route" a propósito: en el
            // frontend, route() es el helper global de Ziggy para generar
            // URLs — llamar a la prop igual lo taparía dentro del componente.
            'expressRoute' => $route,
            'isOwner' => $request->user()->id === $route->client_user_id,
            'isAssignedDriver' => $request->user()->id === $route->assigned_driver_user_id,
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

        $validated = $request->validate(ExpressRoutePublisher::updateRules());

        $this->expressRoutePublisher->update($route, $validated);

        return back()->with('status', 'Expreso actualizado.');
    }

    /**
     * El cliente pausa un Expreso activo (deja de generar carreras) sin
     * cancelar el contrato del todo — puede reanudarlo más adelante.
     */
    public function pause(Request $request, ExpressRoute $route): RedirectResponse
    {
        $this->authorize('update', $route);

        $this->expressRoutePublisher->pause($route);

        return back()->with('status', 'Expreso pausado.');
    }

    public function resume(Request $request, ExpressRoute $route): RedirectResponse
    {
        $this->authorize('update', $route);

        $this->expressRoutePublisher->resume($route);

        return back()->with('status', 'Expreso reanudado.');
    }

    /**
     * Cancela el Expreso definitivamente. No borra el historial de carreras
     * ya generadas (sección 9.6: trazabilidad), solo corta la recurrencia.
     */
    public function cancel(Request $request, ExpressRoute $route): RedirectResponse
    {
        $this->authorize('update', $route);

        $this->expressRoutePublisher->cancel($route);

        return back()->with('status', 'Expreso cancelado.');
    }

    /**
     * Ofertas de Expreso abiertas para un conductor (sección 4.2): las que
     * publicaron clientes de flotas a las que pertenece.
     */
    public function available(Request $request): Response
    {
        $data = $this->expressRouteFinder->availableForDriver($request->user());

        return Inertia::render('Express/Available', [
            'routes' => $data['routes'],
            'myApplications' => $data['myApplications'],
            'assignedRoutes' => $data['assignedRoutes'],
            // Pedido explícito del usuario ("no sé a quién le aparece un
            // Expreso"): con esto la pantalla puede explicar POR QUÉ está
            // vacía, en vez de un genérico "no hay nada" — no es lo mismo no
            // pertenecer a ninguna flota que pertenecer a una sin Expresos
            // abiertos ahora mismo.
            'myFleetCount' => $data['myFleetCount'],
            'canApply' => $data['canApply'],
        ]);
    }
}
