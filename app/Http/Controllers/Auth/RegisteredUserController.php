<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Jobs\ResolveRegistrationNeighborhood;
use App\Mail\WelcomeMail;
use App\Models\City;
use App\Models\Cooperative;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use App\Rules\ValidPhoneNumberLocal;
use App\Services\Haversine;
use App\Services\WhatsAppVerificationSender;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
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
     * reales), por eso vive acá y no en una tabla administrable. Pedido
     * explícito del usuario: solo Sudamérica (el mercado real de la app) —
     * Ecuador, Perú, Colombia, Venezuela, Chile, Argentina.
     */
    public const COUNTRY_CODES = ['+593', '+51', '+57', '+58', '+56', '+54'];

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            // Qué tipo de cuenta quiere crear (consideración agregada al alcance:
            // se pide primero, antes que ningún otro dato, porque cambia a dónde
            // va apenas termina de registrarse — ver el redirect más abajo). No
            // se persiste en `users`: la fuente de verdad del rol sigue siendo
            // `isDriver()`/`isClient()` (sección 3.1), esto es solo para decidir
            // el siguiente paso de la guía.
            'account_type' => ['required', 'string', Rule::in(['cliente', 'conductor', 'cooperativa'])],
            // El formulario nuevo solicita nombre y apellido por separado,
            // pero `name` se conserva temporalmente como entrada compatible
            // para clientes antiguos que todavía tengan assets en caché.
            'name' => ['nullable', 'string', 'max:255', 'required_without:first_name'],
            'first_name' => ['nullable', 'string', 'max:100', 'required_without:name'],
            'last_name' => ['nullable', 'string', 'max:100', 'required_with:first_name'],
            // Sin 'unique:' acá a propósito: el correo duplicado se valida a
            // mano más abajo (mismo criterio que el teléfono), con un
            // mensaje que invita a iniciar sesión en vez del genérico "ya
            // está en uso" de Laravel — Auth/Register.vue lo detecta y
            // ofrece el atajo (pedido explícito del usuario: "la gente se
            // pierde" entre iniciar sesión y crear cuenta).
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
            // El teléfono es clave para que otros clientes puedan encontrar e invitar
            // a este usuario a su flota (sección 3.2), por eso es obligatorio y único.
            // Se arma en dos partes (código de país + número local) para poder
            // validar y normalizar el formato E.164 antes de guardarlo.
            'country_code' => ['required', 'string', Rule::in(self::COUNTRY_CODES)],
            'phone_local' => ['required', 'string', new ValidPhoneNumberLocal],
            'password' => [
                'required', 'confirmed',
                Rules\Password::defaults()->min(8)->mixedCase()->numbers(),
            ],
            // Trazabilidad de referidos (pedido explícito del usuario): quién
            // le compartió el enlace que trajo a esta cuenta nueva — llega en
            // la URL de registro (Referral/Show.vue, Profile/Edit.vue vía
            // ShareProfileQr, o cualquier perfil público) y viaja oculto en el
            // formulario (Auth/Register.vue), nunca lo escribe la persona.
            'ref' => ['nullable', 'integer', 'exists:users,id'],
            // Ubicación real del navegador al registrarse (pedido explícito
            // del usuario: "ver de dónde se registran las personas, por su
            // ubicación") — con su permiso (ver Register.vue), nunca
            // obligatoria: si el navegador la niega o no la soporta, viaja
            // vacía y el registro sigue igual.
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        // Pedido explícito del usuario ("la gente se pierde" entre iniciar
        // sesión y crear cuenta): si el correo o el teléfono ya tienen
        // cuenta, se lo dice claro invitándolo a iniciar sesión en vez de
        // dejarlo solo con "ya está en uso" — Auth/Register.vue detecta
        // estos mensajes puntuales y ofrece el atajo.
        if (User::where('email', $validated['email'])->exists()) {
            throw ValidationException::withMessages([
                'email' => 'Ya existe una cuenta con este correo. ¿Ya tiene una cuenta? Inicie sesión.',
            ]);
        }

        $phone = $validated['country_code'].$validated['phone_local'];

        if (User::where('phone', $phone)->exists()) {
            throw ValidationException::withMessages([
                'phone_local' => 'Ese número de teléfono ya está registrado. ¿Ya tiene una cuenta? Inicie sesión.',
            ]);
        }

        $hasCoordinates = isset($validated['lat'], $validated['lng']);
        $city = $hasCoordinates ? $this->nearestCity((float) $validated['lat'], (float) $validated['lng']) : null;

        $fullName = filled($validated['first_name'] ?? null)
            ? Str::squish($validated['first_name'].' '.$validated['last_name'])
            : Str::squish($validated['name']);

        $user = User::create([
            'name' => $fullName,
            'email' => $validated['email'],
            'phone' => $phone,
            'password' => Hash::make($validated['password']),
            'referred_by_user_id' => $validated['ref'] ?? null,
            'city_id' => $city?->id,
            'registration_lat' => $hasCoordinates ? $validated['lat'] : null,
            'registration_lng' => $hasCoordinates ? $validated['lng'] : null,
        ]);

        // A diferencia de una cuenta creada por Google (contraseña al azar
        // que nadie conoce, ver GoogleAuthController), acá el usuario SÍ
        // acaba de elegir la suya — marcarlo es lo que le permite después
        // cambiarla pidiéndole la actual, en vez de quedar bloqueado como le
        // pasaba a las cuentas de Google (ver PasswordController::update()).
        $user->forceFill(['password_set_at' => now()])->save();

        event(new Registered($user));

        // El nombre del barrio/zona es informativo (panel admin) y depende
        // de un servicio externo — se resuelve aparte, en cola, para no
        // demorar ni arriesgar el registro por eso (ver la clase del job).
        if ($hasCoordinates) {
            ResolveRegistrationNeighborhood::dispatch($user->id, (float) $validated['lat'], (float) $validated['lng']);
        }

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

        if ($validated['account_type'] === 'cooperativa') {
            Cooperative::query()->create([
                'user_id' => $user->id,
                'phone' => $user->phone,
                'email' => $user->email,
                'city_id' => $user->city_id,
            ]);

            return redirect()->route('cooperative.profile.edit')
                ->with('status', '¡Cuenta creada! Complete y envíe la documentación de la cooperativa para validación.');
        }

        // Pedido explícito del usuario ("que se una a su flota"): si vino del
        // enlace de invitación de un conductor, lo volvemos a esa pantalla
        // para que complete el único paso que le falta — agregarlo a su
        // flota nueva — en vez de dejarlo en el Inicio sin rumbo.
        $referrerDriverCode = User::find($validated['ref'] ?? null)?->driverProfile?->invite_code;
        if ($referrerDriverCode) {
            return redirect()->route('referrals.show', $referrerDriverCode)
                ->with('status', '¡Cuenta creada! Ya puede agregarlo a su flota de confianza.');
        }

        return redirect(RouteServiceProvider::HOME);
    }

    /**
     * Ciudad más cercana a la ubicación real que dio el navegador al
     * registrarse (pedido explícito del usuario) — mismo cálculo de
     * cercanía (Haversine) que ya usa OperationsController::notifyNearby()
     * contra conductores, acá contra el catálogo de `cities` (que sí tiene
     * lat/lng propio, a diferencia de `sectors`). Reemplaza directo al
     * `city_id` que antes solo se completaba a mano en el perfil.
     */
    private function nearestCity(float $lat, float $lng): ?City
    {
        return City::query()
            ->where('is_active', true)
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->get(['id', 'lat', 'lng'])
            ->sortBy(fn (City $city) => Haversine::distanceKm($lat, $lng, (float) $city->lat, (float) $city->lng))
            ->first();
    }
}
