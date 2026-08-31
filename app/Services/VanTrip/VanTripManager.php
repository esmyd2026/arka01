<?php

namespace App\Services\VanTrip;

use App\Models\City;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\User;
use App\Models\VanTrip;
use App\Models\VanTripReservation;
use App\Models\VanTripSearchRequest;
use App\Services\Haversine;
use App\Services\PlanLimits;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Viajes tipo VAN/buseta programados: el conductor publica, el cliente
 * explora y reserva por asiento — extraído de VanTripController y
 * VanTripReservationController (roadmap app móvil, "full backend": nunca
 * duplicar una regla de negocio entre web y móvil).
 */
class VanTripManager
{
    public function __construct(private readonly PlanLimits $planLimits) {}

    /**
     * @return array{trips: Collection, cities: Collection, canPublish: bool, driverRatePerKm: ?string, driverCity: ?array, recentDemand: Collection}
     */
    public function forDriver(User $user): array
    {
        return [
            'trips' => VanTrip::query()
                ->where('driver_user_id', $user->id)
                ->with(['originCity', 'destinationCity'])
                ->withSum(['reservations as reserved_seats_count' => fn ($q) => $q->where('status', 'confirmed')], 'seats_reserved')
                ->latest('travel_date')
                ->get(),
            'cities' => City::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'canPublish' => $user->isDriver() && $this->planLimits->forDriver($user)['van_trips_enabled'],
            'driverRatePerKm' => $user->driverProfile?->rate_per_km,
            'driverCity' => $user->city ? ['lat' => (float) $user->city->lat, 'lng' => (float) $user->city->lng] : null,
            'recentDemand' => $this->recentDemand(),
        ];
    }

    private function recentDemand(): Collection
    {
        return VanTripSearchRequest::query()
            ->where('created_at', '>=', now()->subDays(30))
            ->where(function ($query) {
                $query->whereNull('travel_date')->orWhere('travel_date', '>=', Carbon::today());
            })
            ->with(['originCity', 'destinationCity'])
            ->get()
            ->groupBy(fn (VanTripSearchRequest $r) => "{$r->origin_city_id}-{$r->destination_city_id}")
            ->map(fn ($group) => [
                'origin_city' => $group->first()->originCity->name,
                'destination_city' => $group->first()->destinationCity->name,
                'count' => $group->count(),
                'soonest_date' => $group->pluck('travel_date')->filter()->sort()->first()?->toDateString(),
            ])
            ->sortByDesc('count')
            ->take(5)
            ->values();
    }

    public static function storeRules(): array
    {
        return [
            'origin_city_id' => ['required', 'integer', 'different:destination_city_id', 'exists:cities,id'],
            'destination_city_id' => ['required', 'integer', 'exists:cities,id'],
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
        ];
    }

    /**
     * @param  array<int, UploadedFile>  $photos
     */
    public function store(User $user, array $validated, array $photos = []): VanTrip
    {
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

        foreach ($photos as $photo) {
            $trip->photos()->create(['photo_path' => $photo->store('van-trips', 'public')]);
        }

        return $trip;
    }

    /**
     * @return array{trip: VanTrip, isOwner: bool, seatsAvailable: int, myReservation: ?VanTripReservation}
     */
    public function showFor(VanTrip $vanTrip, User $user): array
    {
        $vanTrip->load(['driver.driverProfile', 'originCity', 'destinationCity', 'photos', 'reservations' => fn ($q) => $q->where('status', 'confirmed')->with('client')]);

        return [
            'trip' => $vanTrip,
            'isOwner' => $user->id === $vanTrip->driver_user_id,
            'seatsAvailable' => $vanTrip->seatsAvailable(),
            'myReservation' => $vanTrip->reservations->firstWhere('client_user_id', $user->id),
        ];
    }

    /**
     * @param  array{origin_city_id?: int, destination_city_id?: int, travel_date?: string}  $filters
     * @return array{trips: Collection, fallbackTrips: Collection, searchSaved: bool, cities: Collection}
     */
    public function browse(User $user, array $filters): array
    {
        $query = VanTrip::query()
            ->where('status', 'open')
            ->where('travel_date', '>=', Carbon::today())
            ->with(['driver', 'originCity', 'destinationCity', 'photos'])
            ->withSum(['reservations as reserved_seats_count' => fn ($q) => $q->where('status', 'confirmed')], 'seats_reserved');

        if (! empty($filters['origin_city_id'])) {
            $query->where('origin_city_id', $filters['origin_city_id']);
        }

        if (! empty($filters['destination_city_id'])) {
            $query->where('destination_city_id', $filters['destination_city_id']);
        }

        if (! empty($filters['travel_date'])) {
            $query->whereDate('travel_date', $filters['travel_date']);
        }

        $trips = $query->orderBy('travel_date')->orderBy('departure_time')->get()
            ->filter(fn (VanTrip $trip) => $trip->total_seats > (int) $trip->reserved_seats_count)
            ->values();

        $searchSaved = false;
        $fallbackTrips = collect();

        if ($trips->isEmpty() && ! empty($filters['origin_city_id']) && ! empty($filters['destination_city_id'])) {
            $originId = (int) $filters['origin_city_id'];
            $destinationId = (int) $filters['destination_city_id'];
            $travelDate = ! empty($filters['travel_date']) ? Carbon::parse($filters['travel_date'])->toDateString() : null;

            $alreadySaved = VanTripSearchRequest::query()
                ->where('user_id', $user->id)
                ->where('origin_city_id', $originId)
                ->where('destination_city_id', $destinationId)
                ->when(
                    $travelDate,
                    fn ($q) => $q->whereDate('travel_date', $travelDate),
                    fn ($q) => $q->whereNull('travel_date')
                )
                ->exists();

            if (! $alreadySaved) {
                VanTripSearchRequest::query()->create([
                    'user_id' => $user->id,
                    'origin_city_id' => $originId,
                    'destination_city_id' => $destinationId,
                    'travel_date' => $travelDate,
                ]);
            }

            $searchSaved = true;
        }

        if ($trips->isEmpty()) {
            $myFleetDriverIds = FleetMember::query()
                ->whereIn('fleet_id', Fleet::query()->where('owner_user_id', $user->id)->pluck('id'))
                ->whereNull('left_at')
                ->pluck('driver_user_id');

            $fallbackTrips = VanTrip::query()
                ->where('status', 'open')
                ->where('travel_date', '>=', Carbon::today())
                ->with(['driver', 'originCity', 'destinationCity', 'photos'])
                ->withSum(['reservations as reserved_seats_count' => fn ($q) => $q->where('status', 'confirmed')], 'seats_reserved')
                ->orderBy('travel_date')->orderBy('departure_time')
                ->get()
                ->filter(fn (VanTrip $trip) => $trip->total_seats > (int) $trip->reserved_seats_count)
                ->each(fn (VanTrip $trip) => $trip->is_own_fleet = $myFleetDriverIds->contains($trip->driver_user_id))
                ->sortByDesc('is_own_fleet')
                ->take(10)
                ->values();
        }

        return [
            'trips' => $trips,
            'fallbackTrips' => $fallbackTrips,
            'searchSaved' => $searchSaved,
            'cities' => City::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ];
    }

    public function cancelTrip(VanTrip $vanTrip, User $user): void
    {
        abort_unless($vanTrip->driver_user_id === $user->id, 403);

        $vanTrip->update(['status' => 'cancelled']);
    }

    public function reserveSeats(VanTrip $vanTrip, User $user, int $seatsReserved): void
    {
        abort_if($vanTrip->driver_user_id === $user->id, 403);

        DB::transaction(function () use ($vanTrip, $user, $seatsReserved) {
            // Bloqueamos la fila para que dos reservas simultáneas no se
            // pasen del cupo real (mismo criterio que RideRequestController::accept()).
            $locked = VanTrip::query()->lockForUpdate()->findOrFail($vanTrip->id);

            if (! $locked->isOpenForBooking()) {
                throw ValidationException::withMessages([
                    'trip' => 'Este viaje ya no está abierto a reservas.',
                ]);
            }

            $alreadyReserved = $locked->reservations()
                ->where('client_user_id', $user->id)
                ->where('status', 'confirmed')
                ->exists();

            if ($alreadyReserved) {
                throw ValidationException::withMessages([
                    'trip' => 'Ya tiene una reserva en este viaje — cancélela si quiere cambiar la cantidad de asientos.',
                ]);
            }

            if ($seatsReserved > $locked->seatsAvailable()) {
                throw ValidationException::withMessages([
                    'seats_reserved' => "Solo quedan {$locked->seatsAvailable()} asiento(s) disponible(s).",
                ]);
            }

            VanTripReservation::query()->create([
                'van_trip_id' => $locked->id,
                'client_user_id' => $user->id,
                'seats_reserved' => $seatsReserved,
                'status' => 'confirmed',
                'reserved_at' => now(),
            ]);
        });
    }

    public function cancelReservation(VanTripReservation $reservation, User $user): void
    {
        abort_unless($reservation->client_user_id === $user->id, 403);

        $reservation->update(['status' => 'cancelled']);
    }
}
