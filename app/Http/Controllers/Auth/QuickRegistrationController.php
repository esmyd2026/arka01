<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\ActiveSessionExistsException;
use App\Http\Controllers\Controller;
use App\Mail\QuickRegistrationCodeMail;
use App\Models\User;
use App\Rules\ValidPhoneNumberLocal;
use App\Services\WhatsAppVerificationSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;

/**
 * Registro rápido (pedido explícito del usuario, "que simplemente sea con
 * el numero de telefono... y que cuando inicien le permita actualizar su
 * nombre y apellido y listo") — SOLO para clientes: conductor/cooperativa
 * siguen el registro largo de siempre (RegisteredUserController), porque
 * necesitan datos que el teléfono solo no cubre.
 *
 * Teléfono -> código (WhatsApp, o por correo si dejó uno y WhatsApp no
 * funcionó) -> (una vez logueado) completar nombre en
 * CompleteProfileController. La cuenta se crea ya en sendCode() (no recién
 * al verificar) para poder reusar `User::issuePhoneVerificationCode()` tal
 * cual, sin duplicar esa lógica.
 *
 * Pedido explícito del usuario: si ningún canal funciona (o el código
 * simplemente nunca llega, aunque el envío haya "salido bien"), no lo deja
 * trabado esperando — finishWithoutCode() es el escape final, con una
 * contraseña como prueba alternativa.
 */
class QuickRegistrationController extends Controller
{
    /**
     * Crea (o reusa, si quedó a medias) la cuenta y manda el código —
     * primero por WhatsApp; si no se pudo y dejó un correo real, por ahí.
     * JSON porque este paso vive dentro del wizard de Auth/Register.vue, sin
     * navegar a otra pantalla todavía.
     */
    public function sendCode(Request $request): JsonResponse
    {
        $request->merge([
            'phone_local' => ValidPhoneNumberLocal::normalize($request->input('country_code'), $request->input('phone_local')),
        ]);

        $validated = $request->validate([
            'country_code' => ['required', 'string', Rule::in(RegisteredUserController::COUNTRY_CODES)],
            'phone_local' => ['required', 'string', new ValidPhoneNumberLocal],
            // Pedido explícito del usuario ("priorizar el teléfono pero si
            // no que sea por email"): opcional, solo como respaldo si
            // WhatsApp no funciona — nunca reemplaza al teléfono como dato
            // principal de este registro.
            'email' => ['nullable', 'string', 'email', 'max:255'],
        ]);

        $phone = $validated['country_code'].$validated['phone_local'];
        $email = $validated['email'] ?? null;

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

        if ($email && User::where('email', $email)->where('id', '!=', $existing?->id)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'Ese correo ya está en uso por otra cuenta.',
            ]);
        }

        $user = $existing ?? User::create([
            'name' => 'Nuevo usuario',
            'email' => $email ?: 'tel-'.ltrim($phone, '+').'@sinemail.arka01.local',
            'password' => Str::random(48),
            'phone' => $phone,
            'role' => 'cliente',
        ]);

        // Reintento con un correo nuevo que la primera vez no había escrito
        // (todavía tiene el de relleno, así que no hay nada real que perder).
        if ($email && ! $user->hasRealEmail()) {
            $user->forceFill(['email' => $email])->save();
        }

        $code = $user->issuePhoneVerificationCode();
        $sentByWhatsApp = WhatsAppVerificationSender::enabled() && WhatsAppVerificationSender::sendCode($phone, $code);
        $sentByEmail = false;

        if (! $sentByWhatsApp && $user->hasRealEmail()) {
            try {
                Mail::to($user->email)->send(new QuickRegistrationCodeMail($user, $code));
                $sentByEmail = true;
            } catch (\Throwable $e) {
                Log::warning('No se pudo mandar el código de registro rápido por correo.', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Código de registro rápido enviado.', [
            'user_id' => $user->id,
            'enviado_por_whatsapp' => $sentByWhatsApp,
            'enviado_por_correo' => $sentByEmail,
        ]);

        return response()->json([
            'phone' => $phone,
            // El frontend usa esto solo para el mensaje ("se lo mandamos a
            // su correo") — el botón de "no me llegó" (finishWithoutCode())
            // sigue disponible sin importar el valor, porque un envío
            // "exitoso" de todas formas puede no llegar nunca de verdad.
            'sent_via' => $sentByWhatsApp ? 'whatsapp' : ($sentByEmail ? 'email' : null),
        ]);
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
     * Escape final (pedido explícito del usuario: "que le diga un botón no
     * me llegó el mensaje y que lo deje pasar igual pero que le pida una
     * contraseña"): ni WhatsApp ni correo funcionaron, o el código
     * simplemente nunca llegó — la contraseña que elige acá reemplaza la
     * prueba de verificación que no se pudo completar (mismo criterio de
     * fondo que RegisterUser::execute() cuando el envío falla de verdad: no
     * debería bloquear a nadie por una integración caída).
     */
    public function finishWithoutCode(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string'],
            'password' => [
                'required', 'confirmed',
                Rules\Password::defaults()->min(8)->mixedCase()->numbers(),
            ],
        ]);

        $user = User::where('phone', $validated['phone'])->first();

        // Ya no es una cuenta "pendiente" de este flujo (o directamente no
        // existe) — no tiene sentido dejar poner una contraseña acá.
        if (! $user || $user->phone_verified_at || $user->profile_name_completed_at) {
            throw ValidationException::withMessages([
                'password' => 'No pudimos continuar con este número — intente registrarse de nuevo.',
            ]);
        }

        $user->forceFill([
            'password' => $validated['password'],
            'password_set_at' => now(),
            'phone_verified_at' => now(),
            'phone_verification_code' => null,
            'phone_verification_expires_at' => null,
        ])->save();

        $this->login($user);

        return redirect()->route('complete-profile.show');
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
