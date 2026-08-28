<?php

namespace App\Http\Controllers;

use App\Services\Driver\DriverAvailabilityUpdater;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverLocationController extends Controller
{
    public function __construct(private readonly DriverAvailabilityUpdater $availabilityUpdater) {}

    /**
     * El conductor manda su posición mientras está disponible (sección 9.3).
     * El navegador llama a esto periódicamente desde DriverAvailabilityToggle.vue
     * (cada ~15s vía watchPosition), por eso devuelve JSON en vez de una
     * respuesta Inertia — no tiene sentido navegar de página por un ping de fondo.
     * Lógica real en App\Services\Driver\DriverAvailabilityUpdater (roadmap
     * app móvil, Hito 5) — el mismo conductor móvil manda estos pings igual.
     */
    public function update(Request $request): JsonResponse
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

        return response()->json(['ok' => true]);
    }
}
