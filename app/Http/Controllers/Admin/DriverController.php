<?php

namespace App\Http\Controllers\Admin;

use App\Events\DriverLocationUpdated;
use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use App\Models\DriverTier;
use App\Models\Review;
use App\Models\Ride;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Panel admin de conductores (pedido explícito del usuario): ver quién está
 * disponible ahora mismo con su ubicación, y poder bloquear/deshabilitar/
 * desconectar a un conductor puntual — ver DriverProfile::isSuspended() para
 * el porqué de un solo mecanismo en vez de tres.
 */
class DriverController extends Controller
{
    public function index(): Response
    {
        // "Disponibles ahora" (pedido explícito del usuario: "que cuando no
        // estén disponibles desaparezcan") — el frontend vuelve a pedir esto
        // solo cada tanto (no hace falta WebSocket para un panel de admin,
        // que no necesita latencia de segundos).
        $available = DriverProfile::query()
            ->where('is_available', true)
            ->whereNull('suspended_at')
            ->whereNotNull('current_lat')
            ->whereNotNull('current_lng')
            ->with('user')
            ->get();

        $ratings = Review::query()
            ->whereIn('reviewee_user_id', $available->pluck('user_id'))
            ->selectRaw('reviewee_user_id, avg(rating) as avg_rating, count(*) as review_count')
            ->groupBy('reviewee_user_id')
            ->get()
            ->keyBy('reviewee_user_id');

        $availableDrivers = $available->map(function (DriverProfile $profile) use ($ratings) {
            $rating = $ratings->get($profile->user_id);
            $avgRating = $rating ? round((float) $rating->avg_rating, 1) : 0;

            return [
                'id' => $profile->id,
                'user_id' => $profile->user_id,
                'name' => $profile->user->name,
                'lat' => (float) $profile->current_lat,
                'lng' => (float) $profile->current_lng,
                'average_rating' => $avgRating,
                'tier' => DriverTier::forPoints($profile->total_points)->toBadge(),
            ];
        })->values();

        // Roster completo (pedido explícito del usuario: bloquear/deshabilitar
        // también a uno que ya está desconectado) — con lo que hace falta
        // para decidir: rechazos, carreras hechas, si está suspendido.
        $allDrivers = DriverProfile::query()
            ->with('user')
            ->orderByDesc('is_available')
            ->orderBy('user_id')
            ->get()
            ->map(fn (DriverProfile $profile) => [
                'id' => $profile->id,
                'user_id' => $profile->user_id,
                'name' => $profile->user->name,
                'email' => $profile->user->email,
                'is_available' => $profile->is_available,
                'is_suspended' => $profile->isSuspended(),
                'verification_status' => $profile->verification_status,
                'rides_rejected_count' => $profile->rides_rejected_count,
                'completed_rides_count' => Ride::query()
                    ->where('driver_user_id', $profile->user_id)
                    ->where('status', 'completed')
                    ->count(),
            ])
            ->values();

        return Inertia::render('Admin/Drivers', [
            'availableDrivers' => $availableDrivers,
            'allDrivers' => $allDrivers,
        ]);
    }

    public function suspend(DriverProfile $driverProfile): RedirectResponse
    {
        $wasAvailable = $driverProfile->is_available;

        $driverProfile->forceFill([
            'suspended_at' => now(),
            'is_available' => false,
        ])->save();

        // Si estaba disponible, que desaparezca YA de cualquier mapa/lista
        // abierta (mismo evento que ya usa un ping real de ubicación).
        if ($wasAvailable) {
            broadcast(new DriverLocationUpdated($driverProfile));
        }

        return back()->with('status', 'Conductor suspendido.');
    }

    public function reactivate(DriverProfile $driverProfile): RedirectResponse
    {
        $driverProfile->forceFill(['suspended_at' => null])->save();

        return back()->with('status', 'Conductor reactivado.');
    }
}
