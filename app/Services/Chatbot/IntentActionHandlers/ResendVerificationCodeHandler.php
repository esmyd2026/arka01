<?php

namespace App\Services\Chatbot\IntentActionHandlers;

use App\Models\User;
use App\Services\WhatsAppFreeformSender;

/**
 * "No me llegó mi código" (pedido explícito del usuario, caso real: la
 * plantilla de verificación estaba mal configurada en Meta y el registro
 * rápido/login por código quedaban sin salida). A diferencia de
 * WhatsAppVerificationSender::sendCode() (que manda por PLANTILLA — la
 * misma que puede estar fallando por nombre/idioma mal configurado, límite
 * de Meta, etc.), esto manda el código como texto libre: solo es posible
 * porque la persona ACABA de escribirle al bot, lo que abre la ventana de
 * 24h (ver WhatsAppWebhookController::openWindowFor()) — mismo mecanismo
 * que ya usa WhatsAppFreeformSender::sendSessionTakeoverCode(). El texto
 * libre no depende de ninguna plantilla aprobada, así que este camino sigue
 * funcionando aunque la plantilla esté rota.
 *
 * Cubre las dos situaciones donde puede haber un código pendiente:
 * 1. Registro rápido / verificación de teléfono (User::phone_verification_code).
 * 2. Login sin contraseña (User::login_code, ver PhoneLoginController).
 *
 * Seguridad: $user siempre se resuelve por el NÚMERO QUE ESCRIBIÓ
 * (WhatsAppWebhookController::receive()), nunca por un dato que la persona
 * escriba en el chat — así que el código de una cuenta solo puede llegarle
 * al teléfono de esa misma cuenta, nunca a un tercero.
 */
class ResendVerificationCodeHandler
{
    public function handle(?User $user): string
    {
        if (! $user) {
            return 'No encontré ninguna cuenta con este número, así que no hay ningún código pendiente. ¿Quieres que te ayude a crear una cuenta? Escríbeme "crear cuenta".';
        }

        if (! $user->phone) {
            return 'Tu cuenta todavía no tiene un teléfono declarado — no hay ningún código pendiente para reenviar.';
        }

        if (! $user->phone_verified_at) {
            return $this->resendVerificationCode($user);
        }

        if ($user->login_code && $user->login_code_expires_at?->isFuture()) {
            return $this->resendLoginCode($user);
        }

        return 'Tu número ya está verificado y no tienes ningún inicio de sesión pendiente. ¿Tenías otro problema? Cuéntame.';
    }

    private function resendVerificationCode(User $user): string
    {
        $code = $user->issuePhoneVerificationCode();
        $sent = WhatsAppFreeformSender::sendText(
            $user->phone,
            "Tu código para verificar tu número en Arka01 es: {$code}\n\nVence en 10 minutos."
        );

        if (! $sent) {
            // Mismo criterio que RegisterUser::execute() cuando el envío
            // falla de verdad: no debería bloquear a nadie por una
            // integración caída.
            $user->forceFill([
                'phone_verified_at' => now(),
                'phone_verification_code' => null,
                'phone_verification_expires_at' => null,
            ])->save();

            return 'No pudimos mandarte el código en este momento, así que ya quedó verificado igual — puedes seguir usando la app sin problema.';
        }

        return 'Listo, te mandé un código nuevo por acá mismo.';
    }

    private function resendLoginCode(User $user): string
    {
        $code = $user->issueLoginCode();
        $sent = WhatsAppFreeformSender::sendText(
            $user->phone,
            "Tu código para iniciar sesión en Arka01 es: {$code}\n\nVence en 10 minutos."
        );

        if (! $sent) {
            return 'No pudimos mandarte el código en este momento — prueba iniciar sesión con tu contraseña, o escríbeme de nuevo en un rato.';
        }

        return 'Listo, te mandé un código nuevo por acá mismo — vuelve a la pantalla de inicio de sesión y escríbelo ahí.';
    }
}
