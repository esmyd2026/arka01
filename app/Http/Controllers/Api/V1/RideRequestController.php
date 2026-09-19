<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\IncomingRideRequestResource;
use App\Http\Resources\Api\V1\RideRequestResource;
use App\Http\Resources\Api\V1\RideResource;
use App\Models\RideRequest;
use App\Services\Ride\IncomingRideRequestFinder;
use App\Services\Ride\RideRequestCreator;
use App\Services\Ride\RideRequestResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Solicitar una carrera desde la app móvil
 * (ROADMAP_APLICACION_MOVIL_CAPACITOR.md, Hito 5). Reusa exactamente la
 * misma lógica que la web (App\Services\Ride\RideRequestCreator,
 * RideRequestResponder e IncomingRideRequestFinder, todos extraídos de los
 * controladores web) — mismo criterio que Fleet: ningún canal puede tener
 * una regla de negocio propia.
 *
 * Alcance: lado cliente (pedir, ver estado, cancelar) Y lado conductor
 * (ver solicitudes entrantes, aceptar, rechazar) para carreras inmediatas.
 * Carreras programadas, paradas, cooperativa y contraoferta de precio
 * quedan para después — el backend ya las soporta (mismo
 * RideRequestCreator::rules()/RideRequestResponder::counter()), falta la
 * pantalla.
 */
class RideRequestController extends Controller
{
    public function __construct(
        private readonly RideRequestCreator $rideRequestCreator,
        private readonly RideRequestResponder $rideRequestResponder,
        private readonly IncomingRideRequestFinder $incomingRideRequestFinder,
    ) {}

    /**
     * Solicitudes que este conductor puede atender ahora mismo (dirigidas a
     * él o a toda su flota) — pantalla "Carreras entrantes".
     */
    public function incoming(Request $request): JsonResponse
    {
        $incoming = $this->incomingRideRequestFinder->forDriver($request->user());

        return response()->json([
            'ride_requests' => IncomingRideRequestResource::collection($incoming),
        ]);
    }

    /**
     * El conductor acepta una solicitud entrante — crea la Ride real.
     */
    public function accept(Request $request, RideRequest $rideRequest): JsonResponse
    {
        $ride = $this->rideRequestResponder->accept($rideRequest, $request->user());
        $ride->load(['client', 'driver.driverProfile']);

        return response()->json(['ride' => new RideResource($ride)], 201);
    }

    /**
     * El conductor rechaza una solicitud dirigida a él puntualmente.
     */
    public function reject(Request $request, RideRequest $rideRequest): JsonResponse
    {
        $this->rideRequestResponder->reject($rideRequest, $request->user());

        return response()->json(['message' => 'Solicitud rechazada.']);
    }

    /**
     * El conductor contraoferta un precio distinto al sugerido — su única
     * ronda permitida (App\Services\Ride\RideRequestResponder::counter()).
     */
    public function counter(Request $request, RideRequest $rideRequest): JsonResponse
    {
        $validated = $request->validate([
            'offered_amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $this->rideRequestResponder->counter($rideRequest, $request->user(), (float) $validated['offered_amount']);

        return response()->json(['ride_request' => new RideRequestResource($rideRequest->fresh(['driver.driverProfile', 'ride']))]);
    }

    /**
     * El cliente sube su propia oferta mientras nadie respondió todavía.
     */
    public function raiseOffer(Request $request, RideRequest $rideRequest): JsonResponse
    {
        $validated = $request->validate([
            'offered_amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $this->rideRequestResponder->raiseOffer($rideRequest, $request->user(), (float) $validated['offered_amount']);

        return response()->json(['ride_request' => new RideRequestResource($rideRequest->fresh(['driver.driverProfile', 'ride']))]);
    }

    /**
     * Solicitudes activas del cliente (no la historia completa — para eso
     * ya existe `rides.index` en la web; el móvil todavía no tiene una
     * pantalla de historial). "Activa" = todavía puede cambiar de estado
     * sola (pending/negotiating/waiting) — pensado para que la pantalla de
     * "Solicitar carrera" sepa si ya hay una en curso antes de pedir otra.
     */
    public function index(Request $request): JsonResponse
    {
        $rideRequests = RideRequest::query()
            ->where('client_user_id', $request->user()->id)
            ->whereIn('status', ['pending', 'negotiating', 'waiting'])
            ->with(['driver.driverProfile', 'ride'])
            ->latest('id')
            ->get();

        return response()->json([
            'ride_requests' => RideRequestResource::collection($rideRequests),
        ]);
    }

    public function show(Request $request, RideRequest $rideRequest): JsonResponse
    {
        if ($rideRequest->client_user_id !== $request->user()->id) {
            abort(403);
        }

        $rideRequest->load(['driver.driverProfile', 'ride']);

        return response()->json(['ride_request' => new RideRequestResource($rideRequest)]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->isClient()) {
            throw ValidationException::withMessages([
                'driver_user_id' => 'Los conductores no pueden pedir una carrera — cada cuenta es cliente o conductor, no ambas.',
            ]);
        }

        $validated = $request->validate(RideRequestCreator::rules());

        $rideRequest = $this->rideRequestCreator->create($request->user(), $validated);
        $rideRequest->load(['driver.driverProfile', 'ride']);

        return response()->json(['ride_request' => new RideRequestResource($rideRequest)], 201);
    }

    public function cancel(Request $request, RideRequest $rideRequest): JsonResponse
    {
        $this->rideRequestResponder->cancel($rideRequest, $request->user());

        return response()->json(['message' => 'Solicitud cancelada.']);
    }
}
