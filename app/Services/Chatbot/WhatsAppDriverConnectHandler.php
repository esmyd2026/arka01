<?php

namespace App\Services\Chatbot;

use App\Events\DriverLocationUpdated;
use App\Models\ChatbotConversation;
use App\Models\User;
use App\Services\DriverActivityTracker;
use App\Services\WhatsAppFreeformSender;
use Illuminate\Support\Str;

/**
 * "Soy Conductor" (pedido explícito del usuario: "un conductor se puede
 * conectar también desde el whatsapp, eso quiere decir que cuando existan
 * carreras le pueda avisar por whatsapp"): un conductor ya registrado puede
 * conectarse/desconectarse SIN abrir la app — mismas reglas que
 * DriverLocationController::update() (availabilityBlockReason(), mismo
 * DriverActivityTracker, mismo evento en vivo), solo que sin pedir GPS: usa
 * la última ubicación conocida, porque en cuanto está conectado por WhatsApp
 * también tiene sesión abierta, y DriverProfile::isReachable() ya lo trata
 * como alcanzable por ese canal aunque el GPS esté viejo (ver
 * SweepStaleDriverAvailability, que ya lo protege del barrido de 2 minutos
 * mientras esa sesión siga abierta).
 *
 * Un número sin cuenta (o una cuenta de cliente) que toca "Soy Conductor"
 * pasa por el mismo consentimiento + confirmación de nombre que
 * WhatsAppRideBookingHandler ya usa para clientes, pero crea la cuenta con
 * role 'conductor' — sin DriverProfile (eso necesita fotos/documentos que no
 * se piden por WhatsApp), así que al final se lo manda a terminar el
 * registro en Arka01.
 */
class WhatsAppDriverConnectHandler
{
    public function __construct(private readonly DriverActivityTracker $activityTracker) {}

    public function handle(string $phone, ?User $user, string $text, ChatbotConversation $conversation): bool
    {
        $state = $conversation->pending_intent;
        $starts = in_array(MessageNormalizer::normalize($text), ['soy conductor', 'conectarme', 'quiero conectarme'], true);

        if (! $starts && ! str_starts_with((string) $state, 'WA_DRIVER_')) {
            return false;
        }

        if ($user && $user->isDriver()) {
            return $this->handleRegisteredDriver($phone, $user, $text, $conversation);
        }

        if ($user && ! $user->isDriver()) {
            $this->clear($conversation);
            WhatsAppFreeformSender::sendText($phone, 'Esta cuenta ya está registrada como cliente en Arka01. Para operar como conductor necesita otro número o contactar a soporte.');

            return true;
        }

        return $this->handleUnregistered($phone, $text, $state, $conversation);
    }

    private function handleRegisteredDriver(string $phone, User $user, string $text, ChatbotConversation $conversation): bool
    {
        $profile = $user->driverProfile;
        if (! $profile) {
            $this->clear($conversation);
            WhatsAppFreeformSender::sendText($phone, 'Todavía no activó su perfil de conductor en Arka01. Entre a la app para completarlo.');

            return true;
        }

        if ($text === 'wa_driver_connect') {
            if ($reason = $profile->availabilityBlockReason()) {
                $this->clear($conversation);
                WhatsAppFreeformSender::sendText($phone, $reason);

                return true;
            }

            $profile->update(['is_available' => true]);
            $this->activityTracker->record($profile->user_id, true);
            broadcast(new DriverLocationUpdated($profile));
            $this->clear($conversation);
            WhatsAppFreeformSender::sendText($phone, '✅ Quedó conectado. Le avisaremos por acá cuando le llegue una solicitud.');

            return true;
        }

        // Pedido explícito del usuario ("un botón tipo permanecer conectado
        // para restablecer la sesión de WhatsApp"): tocar este botón ya
        // cuenta como mensaje entrante y reabre la ventana de 24h por sí
        // solo (WhatsAppWebhookController::openWindowFor()) — acá solo hace
        // falta reconocerlo y confirmar, sin tocar disponibilidad.
        if ($text === 'wa_session_keepalive') {
            $this->clear($conversation);
            WhatsAppFreeformSender::sendText($phone, '✅ Perfecto, seguimos en contacto por acá.');

            return true;
        }

        if ($text === 'wa_driver_disconnect') {
            $profile->update(['is_available' => false]);
            $this->activityTracker->record($profile->user_id, false);
            broadcast(new DriverLocationUpdated($profile));
            $this->clear($conversation);
            WhatsAppFreeformSender::sendText($phone, 'Quedó desconectado. Ya no le llegarán solicitudes hasta que vuelva a conectarse.');

            return true;
        }

        // Primer mensaje del flujo ("soy conductor"/"conectarme"): muestra el
        // estado actual con el único botón que corresponde.
        if ($profile->is_available) {
            $this->setState($conversation, 'WA_DRIVER_STATUS', []);
            WhatsAppFreeformSender::sendButtons($phone, 'Está conectado ✅. Los clientes lo ven disponible.', [
                ['id' => 'wa_driver_disconnect', 'title' => 'Desconectarme'],
            ]);

            return true;
        }

        if ($reason = $profile->availabilityBlockReason()) {
            $this->clear($conversation);
            WhatsAppFreeformSender::sendText($phone, $reason);

            return true;
        }

        $this->setState($conversation, 'WA_DRIVER_STATUS', []);
        WhatsAppFreeformSender::sendButtons($phone, 'Está desconectado. ¿Quiere conectarse?', [
            ['id' => 'wa_driver_connect', 'title' => 'Conectarme'],
        ]);

        return true;
    }

    private function handleUnregistered(string $phone, string $text, ?string $state, ChatbotConversation $conversation): bool
    {
        if ($state === null) {
            $this->setState($conversation, 'WA_DRIVER_CONSENT', []);
            WhatsAppFreeformSender::sendButtons($phone, 'Para operar como conductor en Arka01 vamos a crear su cuenta con este número. ¿Está de acuerdo?', [
                ['id' => 'wa_driver_consent_accept', 'title' => 'Acepto'],
                ['id' => 'wa_driver_consent_decline', 'title' => 'No acepto'],
            ]);

            return true;
        }

        if ($state === 'WA_DRIVER_CONSENT') {
            if ($text !== 'wa_driver_consent_accept') {
                $this->clear($conversation);
                WhatsAppFreeformSender::sendText($phone, 'Entendido, no creamos ninguna cuenta.');

                return true;
            }
            $this->setState($conversation, 'WA_DRIVER_NAME', []);
            WhatsAppFreeformSender::sendText($phone, '¿Cuál es su nombre y apellido?');

            return true;
        }

        if ($state === 'WA_DRIVER_NAME') {
            $name = trim($text);
            if (mb_strlen($name) < 3 || mb_strlen($name) > 100) {
                WhatsAppFreeformSender::sendText($phone, 'Escriba un nombre válido, por ejemplo: Juan Pérez.');

                return true;
            }
            $this->setState($conversation, 'WA_DRIVER_NAME_CONFIRM', ['name' => $name]);
            WhatsAppFreeformSender::sendButtons($phone, "¿Confirmamos el nombre: {$name}?", [
                ['id' => 'wa_name_confirm', 'title' => 'Sí'],
                ['id' => 'wa_name_retry', 'title' => 'Cambiar'],
            ]);

            return true;
        }

        if ($state === 'WA_DRIVER_NAME_CONFIRM') {
            if ($text === 'wa_name_retry') {
                $this->setState($conversation, 'WA_DRIVER_NAME', []);
                WhatsAppFreeformSender::sendText($phone, '¿Cuál es su nombre y apellido?');

                return true;
            }
            if ($text !== 'wa_name_confirm') {
                WhatsAppFreeformSender::sendText($phone, 'Toque uno de los botones para continuar.');

                return true;
            }

            $name = ($conversation->context ?? [])['name'] ?? null;
            if (! $name) {
                $this->clear($conversation);
                WhatsAppFreeformSender::sendText($phone, 'Algo falló, escriba "conectarme" para volver a empezar.');

                return true;
            }

            $created = User::query()->create([
                'name' => $name,
                'email' => 'whatsapp+'.Str::lower(Str::random(16)).'@guest.arka01.local',
                'password' => Str::random(48),
                'phone' => $phone,
                'role' => 'conductor',
                'whatsapp_privacy_accepted_at' => now(),
            ]);
            $created->forceFill(['phone_verified_at' => now()])->save();
            $conversation->update(['user_id' => $created->id]);

            $this->clear($conversation);
            WhatsAppFreeformSender::sendText($phone, "✅ Su cuenta quedó creada, {$name}. Entre a arka01.com con este mismo número para completar su vehículo y documentos, y así poder conectarse.");

            return true;
        }

        return true;
    }

    private function setState(ChatbotConversation $conversation, string $state, array $context): void
    {
        $conversation->update(['pending_intent' => $state, 'context' => $context, 'unresolved_attempts' => 0, 'last_message_at' => now()]);
    }

    private function clear(ChatbotConversation $conversation): void
    {
        $conversation->update(['pending_intent' => null, 'context' => null, 'last_message_at' => now()]);
    }
}
