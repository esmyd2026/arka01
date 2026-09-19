<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Registro del token de notificaciones push nativas (FCM en Android, APNs
 * en iOS) — groundwork del Hito 6, roadmap app móvil. El "dispositivo" ya
 * es el propio token de Sanctum (device_id/platform/app_version, ver
 * AuthController::issueToken()), así que el push token es un dato más de
 * esa misma fila, no una tabla aparte.
 *
 * Solo guarda el dato: todavía no hay ningún canal FCM/APNs conectado en
 * App\Notifications\*PushNotification (hoy usan WebPush, para navegador/PWA,
 * un mecanismo distinto) — enviar de verdad a estos tokens es trabajo aparte
 * que además necesita credenciales de Firebase/Apple del usuario.
 */
class DeviceController extends Controller
{
    public function updatePushToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'push_token' => ['nullable', 'string', 'max:255', 'required_with:push_provider'],
            'push_provider' => ['nullable', 'string', Rule::in(['fcm', 'apns']), 'required_with:push_token'],
        ]);

        $request->user()->currentAccessToken()->forceFill([
            'push_token' => $validated['push_token'] ?? null,
            'push_provider' => $validated['push_provider'] ?? null,
        ])->save();

        return response()->json(['message' => 'Token de notificaciones registrado.']);
    }
}
