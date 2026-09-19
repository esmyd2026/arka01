<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\DriverProfileResource;
use App\Http\Resources\Api\V1\DriverStatusResource;
use App\Models\DriverProfile;
use App\Services\Driver\DriverAvailabilityUpdater;
use App\Services\Driver\DriverProfileUpdater;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Conectarse/desconectarse, publicar ubicación general y editar el perfil
 * de conductor desde la app móvil (ROADMAP_APLICACION_MOVIL_CAPACITOR.md,
 * "full backend"). Reusa exactamente la misma lógica que la web
 * (App\Services\Driver\DriverAvailabilityUpdater/DriverProfileUpdater) —
 * mismo gate de disponibilidad, misma cascada de reglas de re-verificación.
 */
class DriverController extends Controller
{
    public function __construct(
        private readonly DriverAvailabilityUpdater $availabilityUpdater,
        private readonly DriverProfileUpdater $driverProfileUpdater,
    ) {}

    public function status(Request $request): JsonResponse
    {
        $driverProfile = $request->user()->driverProfile;

        if (! $driverProfile) {
            return response()->json(['message' => 'Todavía no activó su perfil de conductor.'], 403);
        }

        return response()->json(['driver' => new DriverStatusResource($driverProfile)]);
    }

    public function updateLocation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'is_available' => ['required', 'boolean'],
        ]);

        $driverProfile = $request->user()->driverProfile;

        if (! $driverProfile) {
            abort(403, 'Todavía no activó su perfil de conductor.');
        }

        $this->availabilityUpdater->update(
            $driverProfile,
            (float) $validated['lat'],
            (float) $validated['lng'],
            (bool) $validated['is_available'],
        );

        return response()->json(['driver' => new DriverStatusResource($driverProfile->fresh())]);
    }

    /**
     * Perfil completo de conductor (vehículo, tarifas, cobertura,
     * verificación) — para prellenar la pantalla de edición. Distinto de
     * status(): ese es solo lo mínimo para el switch de disponibilidad.
     */
    public function profile(Request $request): JsonResponse
    {
        $driverProfile = $request->user()->driverProfile;

        return response()->json([
            'profile' => $driverProfile ? new DriverProfileResource($driverProfile) : null,
            // Catálogos estáticos para el formulario de edición — mismos que
            // recibe DriverProfileController::edit() (web) como props de
            // Inertia, expuestos acá porque la app móvil no tiene ese canal.
            'vehicle_types' => DriverProfile::vehicleTypes(),
            'vehicle_amenities' => DriverProfile::vehicleAmenities(),
        ]);
    }

    /**
     * Crea/actualiza el perfil de conductor (vehículo, tarifas, cobertura,
     * formas de pago, documentos si se mandan como multipart) — igual que
     * DriverProfileController::update() (web), incluida la cascada completa
     * de re-verificación.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $profile = $this->driverProfileUpdater->update($request);

        return response()->json(['profile' => new DriverProfileResource($profile->fresh())]);
    }

    /** "Pasarme a cliente" — pausa el perfil de conductor sin borrar nada. */
    public function deactivate(Request $request): JsonResponse
    {
        $this->driverProfileUpdater->deactivate($request->user());

        return response()->json(['message' => 'Listo — ahora es cliente. Puede volver a activarlo cuando quiera.']);
    }

    /** Atajo de un solo toque para volver a ser conductor. */
    public function reactivate(Request $request): JsonResponse
    {
        $this->driverProfileUpdater->reactivate($request->user());

        return response()->json(['profile' => new DriverProfileResource($request->user()->driverProfile->fresh())]);
    }
}
