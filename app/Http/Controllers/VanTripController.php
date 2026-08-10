<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\VanTrip;
use App\Services\Haversine;
use App\Services\PlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Nuevo servicio para conductores tipo VAN/buseta/microbús/turístico (pedido
 * explícito del usuario): viajes programados puntuales que un cliente explora
 * y reserva por asiento — a diferencia de un Expreso, no es recurrente.
 * Gateado por plan (sección de monetización: "puede manejarse como un plan
 * premium exclusivo para conductores").
 */
class VanTripController extends Controller
{
    public function __construct(private readonly PlanLimits $planLimits) {}

    /**
     * Mis viajes VAN publicados (lado conductor).
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('VanTrips/Index', [
            'trips' => VanTrip::query()
                ->where('driver_user_id', $user->id)
                ->with(['originCity', 'destinationCity'])
                ->withSum(['reservations as reserved_seats_count' => fn ($q) => $q->where('status', 'confirmed')], 'seats_reserved')
                ->latest('travel_date')
                ->get(),
            'cities' => City::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'canPublish' => $user->isDriver() && $this->planLimits->forDriver($user)['van_trips_enabled'],
            // Pedido explícito del usuario: sugerir un costo aproximado antes
            // de que ponga su precio por asiento — se calcula del lado del
            // navegador (distancia entre los puntos que marque × esta tarifa),
            // mismo criterio que ya usa "Pedir carrera".
            'driverRatePerKm' => $user->driverProfile?->rate_per_km,
            // Pedido explícito del usuario: el mapa arrancaba siempre en Quito
            // por defecto — con esto, si el navegador no da geolocalización,
            // al menos centra en la ciudad que ya tiene registrada.
            'driverCity' => $user->city ? ['lat' => (float) $user->city->lat, 'lng' => (float) $user->city->lng] : null,
        ]);
    }

    /**
     * Publica un viaje VAN nuevo — gateado por plan y por tener perfil de
     * conductor activado (sección 3.1: cada cuenta es cliente o conductor).
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->isDriver()) {
            throw ValidationException::withMessages([
                'trip' => 'Necesita tener activado su perfil de conductor para publicar un viaje.',
            ]);
        }

        if (! $this->planLimits->forDriver($user)['van_trips_enabled']) {
            throw ValidationException::withMessages([
                'trip' => 'Su plan actual no incluye publicar viajes tipo VAN/turismo — hace falta un plan superior.',
            ]);
        }

        $validated = $request->validate([
            'origin_city_id' => ['required', 'integer', 'different:destination_city_id', 'exists:cities,id'],
            'destination_city_id' => ['required', 'integer', 'exists:cities,id'],
            // Punto exacto de salida/llegada (pedido explícito del usuario):
            // opcional, un viaje se puede seguir publicando solo con la
            // ciudad — pero si se marca uno de los dos, hace falta el par
            // completo para poder calcular la distancia.
            'origin_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:origin_lng'],
            'origin_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:origin_lat'],
            'origin_address' => ['nullable', 'string', 'max:255'],
            'destination_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:destination_lng'],
            'destination_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:destination_lat'],
            'destination_address' => ['nullable', 'string', 'max:255'],
            'travel_date' => ['required', 'date', 'after_or_equal:today'],
            'departure_time' => ['required', 'date_format:H:i'],
            'total_seats' => ['required', 'integer', 'min:1', 'max:60'],
            'price_per_seat' => ['required', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string', 'max:1000'],
            'included_services' => ['nullable', 'array'],
            'included_services.*' => ['string', 'max:80'],
            'luggage_allowance' => ['nullable', 'string', 'max:150'],
            'photos' => ['nullable', 'array', 'max:6'],
            'photos.*' => ['image', 'max:4096'],
        ]);

        // Se recalcula acá, no se confía en el número que mande el navegador
        // (mismo criterio que el precio de una carrera normal: lo que se
        // guarda siempre lo calcula el backend).
        $distanceKm = isset($validated['origin_lat'], $validated['destination_lat'])
            ? Haversine::distanceKm($validated['origin_lat'], $validated['origin_lng'], $validated['destination_lat'], $validated['destination_lng'])
            : null;

        $trip = VanTrip::query()->create([
            'driver_user_id' => $user->id,
            'origin_city_id' => $validated['origin_city_id'],
            'origin_lat' => $validated['origin_lat'] ?? null,
            'origin_lng' => $validated['origin_lng'] ?? null,
            'origin_address' => $validated['origin_address'] ?? null,
            'destination_city_id' => $validated['destination_city_id'],
            'destination_lat' => $validated['destination_lat'] ?? null,
            'destination_lng' => $validated['destination_lng'] ?? null,
            'destination_address' => $validated['destination_address'] ?? null,
            'distance_km' => $distanceKm,
            'travel_date' => $validated['travel_date'],
            'departure_time' => $validated['departure_time'],
            'total_seats' => $validated['total_seats'],
            'price_per_seat' => $validated['price_per_seat'],
            'description' => $validated['description'] ?? null,
            'included_services' => array_values(array_filter($validated['included_services'] ?? [])),
            'luggage_allowance' => $validated['luggage_allowance'] ?? null,
            'status' => 'open',
        ]);

        foreach ($request->file('photos', []) as $photo) {
            $trip->photos()->create(['photo_path' => $photo->store('van-trips', 'public')]);
        }

        return redirect()->route('van-trips.show', $trip);
    }

    /**
     * Detalle de un viaje: el conductor ve la administración (cancelar,
     * quién reservó); un cliente ve el detalle para reservar.
     */
    public function show(Request $request, VanTrip $vanTrip): Response
    {
        $vanTrip->load(['driver.driverProfile', 'originCity', 'destinationCity', 'photos', 'reservations' => fn ($q) => $q->where('status', 'confirmed')->with('client')]);

        $isOwner = $request->user()->id === $vanTrip->driver_user_id;

        return Inertia::render('VanTrips/Show', [
            'trip' => $vanTrip,
            'isOwner' => $isOwner,
            'seatsAvailable' => $vanTrip->seatsAvailable(),
            'myReservation' => $vanTrip->reservations->firstWhere('client_user_id', $request->user()->id),
        ]);
    }

    /**
     * Explorar viajes VAN abiertos (lado cliente): opcionalmente filtrado
     * por ciudad de origen/destino y fecha.
     */
    public function browse(Request $request): Response
    {
        $query = VanTrip::query()
            ->where('status', 'open')
            ->where('travel_date', '>=', Carbon::today())
            ->with(['driver', 'originCity', 'destinationCity', 'photos'])
            ->withSum(['reservations as reserved_seats_count' => fn ($q) => $q->where('status', 'confirmed')], 'seats_reserved');

        if ($request->filled('origin_city_id')) {
            $query->where('origin_city_id', $request->integer('origin_city_id'));
        }

        if ($request->filled('destination_city_id')) {
            $query->where('destination_city_id', $request->integer('destination_city_id'));
        }

        if ($request->filled('travel_date')) {
            $query->whereDate('travel_date', $request->date('travel_date'));
        }

        $trips = $query->orderBy('travel_date')->orderBy('departure_time')->get()
            ->filter(fn (VanTrip $trip) => $trip->total_seats > (int) $trip->reserved_seats_count)
            ->values();

        return Inertia::render('VanTrips/Browse', [
            'trips' => $trips,
            'cities' => City::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'filters' => $request->only(['origin_city_id', 'destination_city_id', 'travel_date']),
        ]);
    }

    /**
     * El conductor cancela su propio viaje — libera a quienes ya habían
     * reservado (queda como historial, no se borra la reserva).
     */
    public function cancel(Request $request, VanTrip $vanTrip): RedirectResponse
    {
        if ($vanTrip->driver_user_id !== $request->user()->id) {
            abort(403);
        }

        $vanTrip->update(['status' => 'cancelled']);

        return back()->with('status', 'Viaje cancelado.');
    }
}
