<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Suscripción a notificaciones push del navegador (sección 9.2 y 9.5: "Web
 * Push API con VAPID, sin costo de servicio de terceros"). El front pide
 * permiso, se suscribe vía PushManager, y guarda acá la suscripción para que
 * App\Notifications\* la usen más adelante.
 */
class PushSubscriptionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
        ]);

        $request->user()->updatePushSubscription(
            $validated['endpoint'],
            $validated['keys']['p256dh'],
            $validated['keys']['auth'],
        );

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $endpoint = $request->validate(['endpoint' => ['required', 'string']])['endpoint'];

        $request->user()->deletePushSubscription($endpoint);

        return response()->json(['ok' => true]);
    }
}
