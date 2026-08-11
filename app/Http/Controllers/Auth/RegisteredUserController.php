<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\WelcomeMail;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use App\Services\WhatsAppVerificationSender;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    /**
     * Código de país aceptado en el formulario (sección "manejemos el código
     * de país" del alcance) — no es un catálogo de negocio como planes o
     * zonas, es una lista fija de referencia (indicativos telefónicos
     * reales), por eso vive acá y no en una tabla administrable.
     */
    public const COUNTRY_CODES = ['+593', '+51', '+57', '+58', '+1', '+34'];

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            // Qué tipo de cuenta quiere crear (consideración agregada al alcance:
            // se pide primero, antes que ningún otro dato, porque cambia a dónde
            // va apenas termina de registrarse — ver el redirect más abajo). No
            // se persiste en `users`: la fuente de verdad del rol sigue siendo
            // `isDriver()`/`isClient()` (sección 3.1), esto es solo para decidir
            // el siguiente paso de la guía.
            'account_type' => ['required', 'string', Rule::in(['cliente', 'conductor'])],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            // El teléfono es clave para que otros clientes puedan encontrar e invitar
            // a este usuario a su flota (sección 3.2), por eso es obligatorio y único.
            // Se arma en dos partes (código de país + número local) para poder
            // validar y normalizar el formato E.164 antes de guardarlo.
            'country_code' => ['required', 'string', Rule::in(self::COUNTRY_CODES)],
            'phone_local' => ['required', 'string', 'regex:/^[0-9]{7,10}$/'],
            'password' => [
                'required', 'confirmed',
                Rules\Password::defaults()->min(8)->mixedCase()->numbers(),
            ],
        ], [
            'phone_local.regex' => 'El número tiene que tener entre 7 y 10 dígitos, sin espacios ni guiones.',
        ]);

        $phone = $validated['country_code'].$validated['phone_local'];

        if (User::where('phone', $phone)->exists()) {
            throw ValidationException::withMessages([
                'phone_local' => 'Ese número de teléfono ya está registrado.',
            ]);
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $phone,
            'password' => Hash::make($validated['password']),
        ]);

        event(new Registered($user));

        Log::info('Cuenta nueva registrada.', ['user_id' => $user->id, 'username' => $user->username, 'member_code' => $user->member_code]);

        // Correo de bienvenida (pedido explícito del usuario): un correo mal
        // configurado o caído no debería tumbar el registro — mismo criterio
        // que el resto de los correos del proyecto (ej. SosAlertController).
        try {
            Mail::to($user->email)->send(new WelcomeMail($user));
        } catch (\Throwable $e) {
            Log::warning('No se pudo enviar el correo de bienvenida.', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        Auth::login($user);

        // Verificación de teléfono por WhatsApp (consideración de seguridad
        // agregada al alcance): si no está configurada todavía, el teléfono
        // queda auto-verificado para no bloquear a nadie por una integración
        // pendiente (mismo criterio que googleLoginEnabled).
        //
        // Bug crítico reportado por el usuario: si SÍ está configurada pero
        // el envío falla de verdad (token vencido, límite de Meta, etc.),
        // antes quedaba igual esperando un código que nunca iba a llegar —
        // EnsurePhoneIsVerified lo trababa ahí para siempre, sin ninguna
        // salida (ni reenviar, que repetía el mismo fallo en silencio, ni un
        // escape del lado admin). Mismo criterio que "no configurada": si el
        // envío no salió, no puede quedar bloqueando la cuenta.
        if (WhatsAppVerificationSender::enabled()) {
            $code = $user->issuePhoneVerificationCode();
            $sent = WhatsAppVerificationSender::sendCode($user->phone, $code);
            Log::info('Código de verificación de teléfono enviado al registrarse.', ['user_id' => $user->id, 'enviado_por_whatsapp' => $sent]);

            if (! $sent) {
                $user->forceFill([
                    'phone_verified_at' => now(),
                    'phone_verification_code' => null,
                    'phone_verification_expires_at' => null,
                ])->save();
            }
        } else {
            $user->forceFill(['phone_verified_at' => now()])->save();
        }

        // "Conductor" elegido en el primer paso del registro: en vez de dejarlo
        // en el Inicio (todavía no puede recibir carreras), lo llevamos directo
        // a completar los datos que activan el perfil (sección 9.5-B) — es el
        // siguiente paso real de la guía, no una pantalla aparte que tenga que
        // encontrar solo.
        if ($validated['account_type'] === 'conductor') {
            return redirect()->route('driver.profile.edit')
                ->with('status', '¡Cuenta creada! Ahora complete los datos de su vehículo para empezar a recibir carreras.');
        }

        return redirect(RouteServiceProvider::HOME);
    }
}
