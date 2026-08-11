<?php

namespace App\Services\Chatbot\IntentActionHandlers;

use App\Models\User;
use App\Services\WhatsAppVerificationSender;

/**
 * "No me llegó mi código" (pedido explícito del usuario, sección 7: "si
 * Arka01 ya tiene implementado un servicio para reenviar códigos, no crear
 * otro sistema paralelo"). Reutiliza EXACTAMENTE los mismos dos pasos que ya
 * usa Auth\PhoneVerificationController::resend(): `issuePhoneVerificationCode()`
 * (App\Models\User) y WhatsAppVerificationSender::sendCode() — ese servicio
 * ya registra el error técnico en Monitoreo si el envío falla, no hay que
 * duplicar ese log acá.
 */
class ResendVerificationCodeHandler
{
    public function handle(?User $user): string
    {
        if (! $user) {
            return 'No encontré ninguna cuenta con este número, así que no hay ningún código pendiente. ¿Querés que te ayude a crear una cuenta? Escribime "crear cuenta".';
        }

        if (! $user->phone) {
            return 'Tu cuenta todavía no tiene un teléfono declarado — no hay ningún código pendiente para reenviar.';
        }

        if ($user->phone_verified_at) {
            return 'Tu número ya está verificado, no hace falta ningún código para eso. ¿Tenías otro problema? Contame.';
        }

        $code = $user->issuePhoneVerificationCode();
        $sent = WhatsAppVerificationSender::sendCode($user->phone, $code);

        if (! $sent) {
            // Mismo criterio que PhoneVerificationController::resend(): si
            // el envío falla de verdad, el teléfono queda auto-verificado
            // en vez de trabar la cuenta.
            $user->forceFill([
                'phone_verified_at' => now(),
                'phone_verification_code' => null,
                'phone_verification_expires_at' => null,
            ])->save();

            return 'No pudimos mandarte el código por WhatsApp en este momento, así que ya quedó verificado igual — podés seguir usando la app sin problema.';
        }

        return 'Listo, te mandé un código nuevo por WhatsApp — puede tardar unos segundos en llegar.';
    }
}
