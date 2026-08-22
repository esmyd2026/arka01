<?php

namespace App\Http\Controllers\Admin;

use App\Events\DriverLocationUpdated;
use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\DriverProfile;
use App\Models\DriverTier;
use App\Models\Review;
use App\Models\Ride;
use App\Services\AdminAuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
    public function index(Request $request): Response
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
        // también a uno que ya está desconectado, y ahora también paginado +
        // filtrado por nombre/correo, ciudad y estado — antes traía los ~90
        // conductores de una sola vez) — con lo que hace falta para decidir:
        // rechazos, carreras hechas, si está suspendido, ciudad, fecha de
        // registro y última actividad real.
        $query = DriverProfile::query()
            ->with(['user.city'])
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = $request->string('q');
                $query->whereHas('user', fn ($query) => $query->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%"));
            })
            ->when($request->filled('city_id'), fn ($query) => $query->whereHas('user', fn ($query) => $query->where('city_id', $request->integer('city_id'))))
            ->when($request->filled('status'), function ($query) use ($request) {
                match ($request->string('status')->toString()) {
                    'available' => $query->where('is_available', true)->whereNull('suspended_at'),
                    'suspended' => $query->whereNotNull('suspended_at'),
                    'offline' => $query->where('is_available', false)->whereNull('suspended_at'),
                    default => null,
                };
            })
            ->orderByDesc('is_available')
            ->orderBy('user_id');

        $paginated = $query->paginate(20)->withQueryString();

        // Carreras completadas y última actividad real (sesiones — pedido
        // explícito del usuario: "fecha de registro y última actividad"), por
        // lote en vez de una consulta por fila (acá son 20 por página, no ~90).
        $driverIds = collect($paginated->items())->pluck('user_id');

        $completedCounts = Ride::query()
            ->whereIn('driver_user_id', $driverIds)
            ->where('status', 'completed')
            ->selectRaw('driver_user_id, count(*) as completed_count')
            ->groupBy('driver_user_id')
            ->pluck('completed_count', 'driver_user_id');

        $lastActivity = DB::table('sessions')
            ->whereIn('user_id', $driverIds)
            ->selectRaw('user_id, max(last_activity) as last_activity')
            ->groupBy('user_id')
            ->pluck('last_activity', 'user_id');

        $paginated->through(fn (DriverProfile $profile) => [
            'id' => $profile->id,
            'user_id' => $profile->user_id,
            'name' => $profile->user->name,
            'email' => $profile->user->email,
            'city' => $profile->user->city?->name,
            'registered_at' => $profile->user->created_at->toIso8601String(),
            'last_active_at' => $lastActivity->has($profile->user_id) ? now()->setTimestamp($lastActivity[$profile->user_id])->toIso8601String() : null,
            'is_available' => $profile->is_available,
            'is_suspended' => $profile->isSuspended(),
            'verification_status' => $profile->verification_status,
            'rides_rejected_count' => $profile->rides_rejected_count,
            'completed_rides_count' => $completedCounts->get($profile->user_id, 0),
            'whatsapp_ride_actions_enabled' => $profile->whatsapp_ride_actions_enabled,
            'service_category' => $profile->service_category,
            'service_category_label' => $profile->serviceCategoryLabel(),
            'vehicle' => trim(implode(' ', array_filter([
                $profile->vehicle_make,
                $profile->vehicle_model,
                $profile->vehicle_year,
            ]))),
            'vehicle_amenities' => collect($profile->vehicle_amenities ?? [])->map(fn (string $amenity) => [
                'key' => $amenity,
                'label' => DriverProfile::vehicleAmenities()[$amenity]['label'] ?? $amenity,
            ])->values(),
        ]);

        return Inertia::render('Admin/Drivers', [
            'availableDrivers' => $availableDrivers,
            'allDrivers' => $paginated,
            'cities' => City::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'filters' => $request->only(['q', 'city_id', 'status']),
            'serviceCategories' => DriverProfile::serviceCategories(),
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

    public function updateWhatsApp(Request $request, DriverProfile $driverProfile): RedirectResponse
    {
        $validated = $request->validate(['enabled' => ['required', 'boolean']]);
        $driverProfile->forceFill(['whatsapp_ride_actions_enabled' => $validated['enabled']])->save();

        return back()->with('status', $validated['enabled']
            ? 'Operación por WhatsApp habilitada para el conductor.'
            : 'El conductor recibirá avisos, pero no podrá operar carreras por WhatsApp.');
    }

    /**
     * La categoría es una decisión administrativa: los checks del conductor
     * sirven como evidencia, pero nunca se convierten automáticamente en una
     * categoría comercial sin revisión humana.
     */
    public function updateCategory(Request $request, DriverProfile $driverProfile): RedirectResponse
    {
        $validated = $request->validate([
            'service_category' => ['nullable', 'string', Rule::in(array_keys(DriverProfile::serviceCategories()))],
        ]);

        $previous = $driverProfile->service_category;
        $driverProfile->forceFill(['service_category' => $validated['service_category']])->save();

        AdminAuditLogger::log(
            adminUserId: $request->user()->id,
            action: 'driver.service_category.update',
            module: 'drivers',
            oldValue: ['driver_profile_id' => $driverProfile->id, 'service_category' => $previous],
            newValue: ['driver_profile_id' => $driverProfile->id, 'service_category' => $validated['service_category']],
        );

        return back()->with('status', $validated['service_category']
            ? 'Categoría de servicio actualizada.'
            : 'Categoría de servicio retirada.');
    }
}
