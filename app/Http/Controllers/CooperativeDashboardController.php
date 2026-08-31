<?php

namespace App\Http\Controllers;

use App\Models\CooperativeDriverMembership;
use App\Models\Review;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Services\Haversine;
use App\Services\Trust\TrustIndexCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CooperativeDashboardController extends Controller
{
    public function __construct(private readonly TrustIndexCalculator $trustIndex) {}

    public function updateDispatchSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate(['automatic_assignment_enabled' => ['required', 'boolean']]);
        $request->user()->cooperative()->firstOrFail()->update($validated);

        return back()->with('status', $validated['automatic_assignment_enabled']
            ? 'Asignación automática activada.'
            : 'Asignación manual activada. Las solicitudes esperarán a que un operador elija la unidad.');
    }

    public function index(Request $request): Response
    {
        $cooperative = $request->user()->cooperative()->firstOrFail();

        $memberships = CooperativeDriverMembership::query()
            ->where('cooperative_id', $cooperative->id)
            ->whereIn('status', ['pending', 'accepted', 'suspended'])
            ->with('driver.driverProfile')
            ->get();

        $requests = RideRequest::query()
            ->where('cooperative_id', $cooperative->id)
            ->whereIn('status', ['pending', 'negotiating'])
            ->with(['client', 'driver', 'ride'])
            ->latest('requested_at')->limit(20)->get();
        $clientIds = $requests->pluck('client_user_id')->unique();
        $clientRideStats = Ride::query()->whereIn('client_user_id', $clientIds)
            ->selectRaw("client_user_id, sum(case when status = 'completed' then 1 else 0 end) as completed_rides, sum(case when status = 'cancelled' then 1 else 0 end) as cancelled_rides")
            ->groupBy('client_user_id')->get()->keyBy('client_user_id');
        $clientReviewStats = Review::query()->whereIn('reviewee_user_id', $clientIds)
            ->selectRaw('reviewee_user_id, avg(rating) as average_rating, count(*) as review_count')
            ->groupBy('reviewee_user_id')->get()->keyBy('reviewee_user_id');

        // Una unidad en carrera tiene prioridad visual sobre el switch de
        // disponibilidad. Así el operador no interpreta como libre a un
        // conductor que todavía conserva `is_available = true`.
        $activeRidesByDriver = Ride::query()
            ->whereIn('driver_user_id', $memberships->pluck('driver_user_id'))
            ->where('status', 'in_progress')
            ->with('client:id,name')
            ->latest('started_at')
            ->get()
            ->keyBy('driver_user_id');

        return Inertia::render('Cooperative/Dashboard', [
            'cooperative' => $cooperative,
            'stats' => [
                'clients' => $cooperative->clientLinks()->count(),
                'drivers' => $memberships->where('status', 'accepted')->count(),
                'available' => $memberships->where('status', 'accepted')->filter(fn ($membership) => (bool) $membership->driver->driverProfile?->is_available)->count(),
                'pendingDrivers' => $memberships->where('status', 'pending')->count(),
                'pendingRequests' => RideRequest::query()->where('cooperative_id', $cooperative->id)->where('status', 'pending')->count(),
                'scheduledRequests' => RideRequest::query()->where('cooperative_id', $cooperative->id)->where('is_scheduled', true)->whereIn('status', ['pending', 'accepted'])->count(),
                'activeRequests' => RideRequest::query()->where('cooperative_id', $cooperative->id)->where('status', 'accepted')->count(),
            ],
            'requests' => $requests
                ->map(function ($rideRequest) use ($memberships, $clientRideStats, $clientReviewStats) {
                    $rides = $clientRideStats->get($rideRequest->client_user_id);
                    $reviews = $clientReviewStats->get($rideRequest->client_user_id);
                    $rideRequest->client_stats = [
                        'completed_rides' => (int) ($rides?->completed_rides ?? 0),
                        'cancelled_rides' => (int) ($rides?->cancelled_rides ?? 0),
                        'average_rating' => round((float) ($reviews?->average_rating ?? 0), 1),
                        'review_count' => (int) ($reviews?->review_count ?? 0),
                        'trust' => $this->trustIndex->calculate($rideRequest->client),
                    ];
                    // Tiempo urbano estimado de la carrera. Se muestra como
                    // referencia operativa y no se confunde con el ETA de cada
                    // conductor hasta el origen, calculado más abajo.
                    $rideRequest->trip_eta_minutes = max(3, (int) ceil((float) $rideRequest->distance_km / 0.45));
                    $currentRecommendation = collect($rideRequest->smart_dispatch_snapshot ?? [])
                        ->firstWhere('driver_user_id', $rideRequest->driver_user_id);
                    $rideRequest->smart_dispatch_recommendation = $currentRecommendation ? [
                        'score' => $currentRecommendation['score'] ?? null,
                        'reason' => $currentRecommendation['reason'] ?? 'Mejor opción disponible',
                        'version' => $rideRequest->smart_dispatch_version,
                    ] : null;
                    $rideRequest->available_drivers = $memberships->where('status', 'accepted')->map(function ($membership) use ($rideRequest) {
                        $profile = $membership->driver->driverProfile;
                        $distance = $profile?->current_lat !== null && $profile?->current_lng !== null
                            ? round(Haversine::distanceKm((float) $rideRequest->origin_lat, (float) $rideRequest->origin_lng, (float) $profile->current_lat, (float) $profile->current_lng), 1) : null;

                        return ['user_id' => $membership->driver_user_id, 'name' => $membership->driver->name,
                            'available' => (bool) $profile?->is_available, 'verified' => $profile?->verification_status === 'approved',
                            'distance_km' => $distance, 'eta_minutes' => $distance !== null ? max(1, (int) ceil($distance / 0.45)) : null,
                            'vehicle' => trim(implode(' ', array_filter([$profile?->vehicle_make, $profile?->vehicle_model]))),
                            'plate' => $profile?->vehicle_plate, 'color' => $profile?->vehicle_color,
                            'avatar_url' => $membership->driver->avatar_url];
                    })->sortBy(fn ($driver) => $driver['distance_km'] ?? PHP_FLOAT_MAX)->values();

                    return $rideRequest;
                }),
            'drivers' => $memberships
                ->where('status', 'accepted')
                ->map(function ($membership) use ($activeRidesByDriver) {
                    $profile = $membership->driver->driverProfile;
                    $activeRide = $activeRidesByDriver->get($membership->driver_user_id);
                    $operationalStatus = $activeRide
                        ? 'in_ride'
                        : (($profile?->is_available && ! $profile->isStale()) ? 'active' : 'inactive');

                    return [
                        'membership_id' => $membership->id,
                        'user_id' => $membership->driver_user_id,
                        'name' => $membership->driver->name,
                        'avatar_url' => $membership->driver->avatar_url,
                        'available' => $operationalStatus === 'active',
                        'operational_status' => $operationalStatus,
                        'verified' => $profile?->verification_status === 'approved',
                        'vehicle' => trim(implode(' ', array_filter([$profile?->vehicle_make, $profile?->vehicle_model]))),
                        'plate' => $profile?->vehicle_plate,
                        'lat' => $profile?->current_lat,
                        'lng' => $profile?->current_lng,
                        'location_updated_at' => $profile?->location_updated_at?->toIso8601String(),
                        'active_ride' => $activeRide ? [
                            'id' => $activeRide->id,
                            'client_name' => $activeRide->client?->name,
                        ] : null,
                    ];
                })
                ->values(),
        ]);
    }
}
