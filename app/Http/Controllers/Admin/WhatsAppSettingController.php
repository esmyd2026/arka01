<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\ChatbotMessage;
use App\Models\WhatsAppSetting;
use App\Services\AdminAuditLogger;
use App\Services\WhatsAppRideAccess;
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
        'notify_ride_accepted', 'notify_ride_started', 'notify_ride_arrived',
        'notify_ride_picked_up', 'notify_ride_completed', 'notify_new_ride_alert',
        'notify_cooperative_invitation', 'notify_scheduled_reminder', 'notify_scheduled_overdue',
        'notify_offer_expired', 'notify_driver_disconnected',
        'estimated_cost_per_message',
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
                'estimated_cost_per_message' => (float) $settings->estimated_cost_per_message,
            ],
            // Pedido explícito del usuario: "ayudame a configurar los
            // modulos que yo active de envios de whatsapp... y si las
            // desactivo entonce esas notificaciones no llegaran" — un
            // toggle por tipo de aviso (además del apagado general de
            // arriba), con cuánto se mandó de cada uno en los últimos 30
            // días y el costo estimado, para decidir con datos reales qué
            // conviene apagar.
            'notificationTypes' => $this->notificationTypesWithStats($settings),
            'messageStats' => $this->messageStats($settings),
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

    /**
     * Un renglón por tipo de aviso con su estado actual y cuánto costó en
     * los últimos 30 días — mismo criterio que
     * App\Http\Controllers\Admin\SurveyMetricsController: se cuenta en PHP
     * sobre la colección ya traída, sin agregación SQL sobre la columna
     * `meta` (json). El volumen de mensajes salientes no amerita más.
     *
     * @return array<int, array{key: string, label: string, group: string, enabled: bool, count_last_30_days: int, estimated_cost_last_30_days: float}>
     */
    private function notificationTypesWithStats(WhatsAppSetting $settings): array
    {
        $countsByType = $this->outboundCountsByType();
        $costPerMessage = (float) $settings->estimated_cost_per_message;

        return collect(WhatsAppRideAccess::NOTIFICATION_TYPES)
            ->map(function (array $meta, string $key) use ($settings, $countsByType, $costPerMessage) {
                $count = $countsByType[$key] ?? 0;

                return [
                    'key' => $key,
                    'label' => $meta['label'],
                    'group' => $meta['group'],
                    'enabled' => (bool) $settings->{"notify_{$key}"},
                    'count_last_30_days' => $count,
                    'estimated_cost_last_30_days' => round($count * $costPerMessage, 4),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function outboundCountsByType(): array
    {
        return ChatbotMessage::query()
            ->where('direction', 'out')
            ->where('created_at', '>=', now()->subDays(30))
            ->get(['meta'])
            ->filter(fn (ChatbotMessage $message) => (bool) ($message->meta['successful'] ?? false))
            ->countBy(fn (ChatbotMessage $message) => $message->meta['type'] ?? null)
            ->all();
    }

    /**
     * Pedido explícito del usuario: "dame las cantidades de mensajes...
     * coloquemos precios estimados por las cantidades de mensajes
     * enviados" — totales generales (todo tipo de mensaje saliente, no solo
     * los apagables de arriba), para tener una foto completa del costo real.
     *
     * @return array{today: int, last_7_days: int, last_30_days: int, all_time: int, estimated_cost_last_30_days: float, estimated_cost_all_time: float}
     */
    private function messageStats(WhatsAppSetting $settings): array
    {
        $outbound = ChatbotMessage::query()
            ->where('direction', 'out')
            ->get(['meta', 'created_at'])
            ->filter(fn (ChatbotMessage $message) => (bool) ($message->meta['successful'] ?? false));

        $costPerMessage = (float) $settings->estimated_cost_per_message;
        $last30Days = $outbound->filter(fn (ChatbotMessage $message) => $message->created_at->gte(now()->subDays(30)));

        return [
            'today' => $outbound->filter(fn (ChatbotMessage $message) => $message->created_at->gte(now()->startOfDay()))->count(),
            'last_7_days' => $outbound->filter(fn (ChatbotMessage $message) => $message->created_at->gte(now()->subDays(7)))->count(),
            'last_30_days' => $last30Days->count(),
            'all_time' => $outbound->count(),
            // 4 decimales (no 2): a $0.0012/mensaje, un volumen bajo
            // redondearía siempre a $0.00 y el indicador no diría nada.
            'estimated_cost_last_30_days' => round($last30Days->count() * $costPerMessage, 4),
            'estimated_cost_all_time' => round($outbound->count() * $costPerMessage, 4),
        ];
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
            // Pedido explícito del usuario: "que yo las active o desactive".
            'notify_ride_accepted' => ['sometimes', 'boolean'],
            'notify_ride_started' => ['sometimes', 'boolean'],
            'notify_ride_arrived' => ['sometimes', 'boolean'],
            'notify_ride_picked_up' => ['sometimes', 'boolean'],
            'notify_ride_completed' => ['sometimes', 'boolean'],
            'notify_new_ride_alert' => ['sometimes', 'boolean'],
            'notify_cooperative_invitation' => ['sometimes', 'boolean'],
            'notify_scheduled_reminder' => ['sometimes', 'boolean'],
            'notify_scheduled_overdue' => ['sometimes', 'boolean'],
            'notify_offer_expired' => ['sometimes', 'boolean'],
            'notify_driver_disconnected' => ['sometimes', 'boolean'],
            'estimated_cost_per_message' => ['sometimes', 'numeric', 'min:0', 'max:1'],
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
