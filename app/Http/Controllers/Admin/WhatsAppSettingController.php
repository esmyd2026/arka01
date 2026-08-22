<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\WhatsAppSetting;
use App\Services\AdminAuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuración → Integraciones → WhatsApp (pedido explícito del usuario,
 * roadmap de mejoras sección 8): "evitar tener que modificar constantemente
 * el .env". Ver App\Services\WhatsAppConfig para la jerarquía real que usa
 * el resto de la app (base de datos primero, .env como respaldo).
 */
class WhatsAppSettingController extends Controller
{
    /**
     * Campos sensibles: nunca se mandan al frontend, ni siquiera acá en
     * `edit()` — solo si HAY algo configurado (para pintar el campo como
     * "ya cargado") o no.
     */
    private const SENSITIVE_FIELDS = ['token', 'webhook_verify_token', 'app_secret'];

    private const ALL_FIELDS = [
        'token', 'phone_number_id', 'verification_template',
        'business_number', 'webhook_verify_token', 'app_secret',
        'ride_notifications_enabled', 'driver_ride_actions_enabled',
        'client_ride_booking_enabled', 'privacy_notice_text',
    ];

    public function edit(): Response
    {
        $settings = WhatsAppSetting::current();

        return Inertia::render('Admin/Integrations/WhatsApp', [
            'settings' => [
                'has_token' => filled($settings->token),
                'has_app_secret' => filled($settings->app_secret),
                'has_webhook_verify_token' => filled($settings->webhook_verify_token),
                'phone_number_id' => $settings->phone_number_id,
                'verification_template' => $settings->verification_template,
                'business_number' => $settings->business_number,
                'updated_at' => $settings->updated_at?->toIso8601String(),
                'updated_by_name' => $settings->updatedBy?->name,
                'ride_notifications_enabled' => $settings->ride_notifications_enabled,
                'driver_ride_actions_enabled' => $settings->driver_ride_actions_enabled,
                'client_ride_booking_enabled' => $settings->client_ride_booking_enabled,
                'privacy_notice_text' => $settings->privacy_notice_text,
            ],
            // Para que la pantalla pueda avisar "tampoco hay nada en .env"
            // cuando ni la base ni el .env tienen un valor cargado — nunca
            // el valor real, solo si existe (mismo criterio que arriba).
            'envFallback' => [
                'has_token' => filled(config('services.whatsapp.token')),
                'has_app_secret' => filled(config('services.whatsapp.app_secret')),
                'has_webhook_verify_token' => filled(config('services.whatsapp.webhook_verify_token')),
                'phone_number_id' => config('services.whatsapp.phone_number_id'),
                'verification_template' => config('services.whatsapp.verification_template'),
                'business_number' => config('services.whatsapp.business_number'),
            ],
            // Auditoría (sección 18): único módulo con historial por ahora,
            // se muestra acá mismo en vez de una pantalla aparte casi vacía.
            'auditLogs' => AdminAuditLog::query()
                ->where('module', 'integrations')
                ->with('admin')
                ->latest('created_at')
                ->limit(20)
                ->get(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['nullable', 'string', 'max:1000'],
            'phone_number_id' => ['nullable', 'string', 'max:50'],
            'verification_template' => ['nullable', 'string', 'max:100'],
            'business_number' => ['nullable', 'string', 'max:20'],
            'webhook_verify_token' => ['nullable', 'string', 'max:255'],
            'app_secret' => ['nullable', 'string', 'max:255'],
            // `sometimes` conserva compatibilidad con actualizaciones
            // parciales (token, número o plantilla) y con clientes antiguos.
            'ride_notifications_enabled' => ['sometimes', 'boolean'],
            'driver_ride_actions_enabled' => ['sometimes', 'boolean'],
            'client_ride_booking_enabled' => ['sometimes', 'boolean'],
            'privacy_notice_text' => ['nullable', 'string', 'max:2000'],
        ]);

        $settings = WhatsAppSetting::current();
        $before = $settings->only(self::ALL_FIELDS);

        // Campo sensible en blanco = "no tocar" (ya está guardado, no hace
        // falta volver a pegarlo cada vez que se edita otra cosa acá). Los
        // no sensibles sí se pueden dejar en blanco de verdad — vuelven a
        // caer al .env, no hay ningún secreto que esconder ahí.
        foreach (self::SENSITIVE_FIELDS as $key) {
            if (blank($validated[$key] ?? null)) {
                unset($validated[$key]);
            }
        }

        $validated['updated_by'] = $request->user()->id;
        $settings->update($validated);

        AdminAuditLogger::log(
            adminUserId: $request->user()->id,
            action: 'whatsapp_settings.update',
            module: 'integrations',
            oldValue: $before,
            newValue: $settings->fresh()->only(self::ALL_FIELDS),
            sensitiveKeys: self::SENSITIVE_FIELDS,
        );

        return back()->with('status', 'Configuración de WhatsApp actualizada.');
    }
}
