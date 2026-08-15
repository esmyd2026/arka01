<?php

namespace App\Http\Controllers;

use App\Models\CooperativeDriverMembership;
use App\Models\RideRequest;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CooperativeDashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $cooperative = $request->user()->cooperative()->firstOrFail();

        $memberships = CooperativeDriverMembership::query()
            ->where('cooperative_id', $cooperative->id)
            ->whereIn('status', ['pending', 'accepted', 'suspended'])
            ->with('driver.driverProfile')
            ->get();

        return Inertia::render('Cooperative/Dashboard', [
            'cooperative' => $cooperative,
            'stats' => [
                'drivers' => $memberships->where('status', 'accepted')->count(),
                'available' => $memberships->where('status', 'accepted')->filter(fn ($membership) => (bool) $membership->driver->driverProfile?->is_available)->count(),
                'pendingDrivers' => $memberships->where('status', 'pending')->count(),
                'pendingRequests' => RideRequest::query()->where('cooperative_id', $cooperative->id)->where('status', 'pending')->count(),
                'scheduledRequests' => RideRequest::query()->where('cooperative_id', $cooperative->id)->where('is_scheduled', true)->whereIn('status', ['pending', 'accepted'])->count(),
                'activeRequests' => RideRequest::query()->where('cooperative_id', $cooperative->id)->where('status', 'accepted')->count(),
            ],
            'requests' => RideRequest::query()
                ->where('cooperative_id', $cooperative->id)
                ->whereIn('status', ['pending', 'negotiating', 'accepted'])
                ->with(['client', 'driver', 'ride'])
                ->latest('requested_at')
                ->limit(20)
                ->get(),
            'drivers' => $memberships
                ->where('status', 'accepted')
                ->map(fn ($membership) => [
                    'user_id' => $membership->driver_user_id,
                    'name' => $membership->driver->name,
                    'available' => (bool) $membership->driver->driverProfile?->is_available,
                    'verified' => $membership->driver->driverProfile?->verification_status === 'approved',
                ])
                ->values(),
        ]);
    }
}
