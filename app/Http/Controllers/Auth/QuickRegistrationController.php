<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\ActiveSessionExistsException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\ValidPhoneNumberLocal;
use App\Services\WhatsAppVerificationSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Registro rápido (pedido explícito del usuario, "que simplemente sea con
 * el numero de telefono... y que cuando inicien le permita actualizar su
 * nombre y apellido y listo") — SOLO para clientes: conductor/cooperativa
 * siguen el registro largo de siempre (RegisteredUserController), porque
 * necesitan datos que el teléfono solo no cubre.
 *
 * Dos pasos, sin contraseña: teléfono -> código de WhatsApp -> (una vez
 * logueado) completar nombre en CompleteProfileController. La cuenta se crea
 * ya en sendCode() (no recién al verificar) para poder reusar
 * `User::issuePhoneVerificationCode()`/`WhatsAppVerificationSender` tal
 * cual, sin duplicar esa lógica.
 */
class QuickRegistrationController extends Controller
{
    /**
     * Crea (o reusa, si quedó a medias) la cuenta y manda el código por
     * WhatsApp. JSON porque este paso vive dentro del wizard de
     * Auth/Register.vue, sin navegar a otra pantalla todavía.
     */
    public function sendCode(Request $request): JsonResponse
    {
        $request->merge([
            'phone_local' => ValidPhoneNumberLocal::normalize($request->input('country_code'), $request->input('phone_local')),
        ]);

        $validated = $request->validate([
            'country_code' => ['required', 'string', Rule::in(RegisteredUserController::COUNTRY_CODES)],
            'phone_local' => ['required', 'string', new ValidPhoneNumberLocal],
        ]);

        $phone = $validated['country_code'].$validated['phone_local'];

        $existing = User::where('phone', $phone)->first();

        // Si ya hay una cuenta REAL con ese teléfono (llegó a verificarlo o a
        // completar su nombre), es un teléfono ocupado de verdad. Si lo que
        // hay es una cuenta a medias (abandonó el paso del código la vez
        // anterior), se reusa esa misma fila en vez de rechazarla — sin esto,
        // cualquiera que no llegara a escribir el código a tiempo quedaría
        // con ese número bloqueado para siempre.
        if ($existing && ($existing->phone_verified_at || $existing->profile_name_completed_at)) {
            throw ValidationException::withMessages([
                'phone_local' => 'Ese número de teléfono ya está registrado. ¿Ya tiene una cuenta? Inicie sesión.',
            ]);
        }

        $user = $existing ?? User::create([
            'name' => 'Nuevo usuario',
            'email' => 'tel-'.ltrim($phone, '+').'@sinemail.arka01.local',
            'password' => Str::random(48),
            'phone' => $phone,
            'role' => 'cliente',
        ]);

        if (! WhatsAppVerificationSender::enabled()) {
            // Sin la integración prendida no hay forma de mandar ni de
            // esperar un código que nunca va a llegar (mismo criterio que
            // RegisterUser::execute() con el registro normal) — se verifica
            // solo y se loguea de una, en vez de dejarlo trabado.
            return $this->bypassAndLogin($user);
        }

        $code = $user->issuePhoneVerificationCode();
        $sent = WhatsAppVerificationSender::sendCode($phone, $code);

        Log::info('Código de registro rápido enviado.', ['user_id' => $user->id, 'enviado_por_whatsapp' => $sent]);

        if (! $sent) {
            return $this->bypassAndLogin($user);
        }

        return response()->json(['verified' => false, 'phone' => $phone]);
    }

    /**
     * Confirma el código y recién ahí inicia sesión — a esta altura la
     * cuenta ya existe (creada en sendCode()), solo faltaba probar que el
     * teléfono es de verdad de quien lo escribió.
     */
    public function verifyCode(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $user = User::where('phone', $validated['phone'])->first();

        if (! $user || ! $user->verifyPhoneCode($validated['code'])) {
            throw ValidationException::withMessages([
                'code' => 'Ese código no es válido o ya venció.',
            ]);
        }

        $this->login($user);

        return redirect()->route('complete-profile.show');
    }

    /**
     * WhatsApp apagado o caído (ver sendCode()): se marca el teléfono como
     * verificado sin probarlo de verdad (mismo nivel de confianza que
     * RegisterUser::execute() en el mismo caso) y se loguea directo.
     */
    private function bypassAndLogin(User $user): JsonResponse
    {
        $user->forceFill(['phone_verified_at' => now()])->save();

        $this->login($user);

        return response()->json(['verified' => true, 'redirect' => route('complete-profile.show')]);
    }

    /**
     * Mismo manejo de sesión única que el resto de los puntos de entrada sin
     * contraseña (GoogleAuthController::callback()): Auth::login() dispara el
     * mismo evento Login de siempre (App\Listeners\EnforceSingleActiveSession),
     * así que puede rechazar por una sesión activa en otro lado — acá no hay
     * ningún formulario de login al que devolver el mensaje, por eso viaja
     * por 'status'/'login_hint' (que Auth/Login.vue ya sabe mostrar).
     */
    private function login(User $user): void
    {
        try {
            Auth::login($user);
        } catch (ActiveSessionExistsException $e) {
            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }
    }
}
