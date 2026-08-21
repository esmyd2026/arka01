<?php

namespace App\Http\Controllers;

use App\Events\DriverLocationUpdated;
use App\Jobs\NotifyDriverDisconnectedByWhatsApp;
use App\Services\DriverActivityTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverLocationController extends Controller
{
    public function __construct(private readonly DriverActivityTracker $activityTracker) {}

    /**
     * El conductor manda su posición mientras está disponible (sección 9.3).
     * El navegador llama a esto periódicamente desde DriverAvailabilityToggle.vue
     * (cada ~15s vía watchPosition), por eso devuelve JSON en vez de una
     * respuesta Inertia — no tiene sentido navegar de página por un ping de fondo.
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

        // Panel admin (pedido explícito del usuario: "bloquear o deshabilitar
        // o desconectar"): mientras esté suspendido, ni siquiera puede
        // reconectarse a sí mismo — el bloqueo lo levanta un admin, no él.
        // Solo se bloquea el encendido; desconectarse siempre debe seguir
        // siendo posible. La regla incluye perfil completo, documentos,
        // aprobación administrativa, suspensión y pausa del perfil.
        if ($validated['is_available'] && ($reason = $driverProfile->availabilityBlockReason())) {
            abort(403, $reason);
        }

        // Pedido explícito del usuario: si se está desconectando A PROPÓSITO
        // (no un ping de fondo con is_available ya en true), avisarle por
        // WhatsApp para que sepa que dejó de recibir carreras — antes de
        // actualizar, para comparar contra el estado real anterior.
        $wasAvailable = $driverProfile->is_available;

        $driverProfile->update([
            'current_lat' => $validated['lat'],
            'current_lng' => $validated['lng'],
            'is_available' => $validated['is_available'],
            'location_updated_at' => now(),
        ]);

        $this->activityTracker->record($driverProfile->user_id, (bool) $validated['is_available']);

        if ($wasAvailable && ! $validated['is_available']) {
            NotifyDriverDisconnectedByWhatsApp::dispatch($driverProfile->user_id);
        }

        broadcast(new DriverLocationUpdated($driverProfile))->toOthers();

        return response()->json(['ok' => true]);
    }
}
