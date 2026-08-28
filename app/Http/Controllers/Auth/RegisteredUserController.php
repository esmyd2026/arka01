<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\RegisterUser;
use App\Http\Controllers\Controller;
use App\Models\Cooperative;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use App\Rules\ValidPhoneNumberLocal;
use App\Services\ReferralAttribution;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
    public function create(Request $request, ReferralAttribution $referralAttribution): Response
    {
        $referralAttribution->remember($request);

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

    public function store(Request $request, ReferralAttribution $referralAttribution, RegisterUser $registerUser): RedirectResponse
    {
        // Pedido explícito del usuario: si escribe el 0 inicial (ej.
        // "0988492339"), se lo quitamos solo en vez de rechazarlo.
        $request->merge([
            'phone_local' => ValidPhoneNumberLocal::normalize($request->input('country_code'), $request->input('phone_local')),
        ]);

        $validated = $request->validate([
            // Qué tipo de cuenta quiere crear (consideración agregada al alcance:
            // se pide primero, antes que ningún otro dato, porque cambia a dónde
            // va apenas termina de registrarse — ver el redirect más abajo). El
            // valor en sí no se guarda tal cual: la fuente de verdad del rol
            // sigue siendo `isDriver()`/`isClient()` (sección 3.1, según exista
            // o no un DriverProfile de verdad) — 'conductor' solo se traduce en
            // `intends_to_drive` (bug reportado por el usuario, ver más abajo),
            // que es una señal de "le falta terminar", no el rol en sí.
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
            'ref' => ['nullable', 'uuid', 'exists:users,public_id'],
            // Ubicación real del navegador al registrarse (pedido explícito
            // del usuario: "ver de dónde se registran las personas, por su
            // ubicación") — con su permiso (ver Register.vue), nunca
            // obligatoria: si el navegador la niega o no la soporta, viaja
            // vacía y el registro sigue igual.
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $phone = $validated['country_code'].$validated['phone_local'];

        $user = $registerUser->execute([
            'account_type' => $validated['account_type'],
            'name' => $validated['name'] ?? null,
            'first_name' => $validated['first_name'] ?? null,
            'last_name' => $validated['last_name'] ?? null,
            'email' => $validated['email'],
            'phone' => $phone,
            'password' => $validated['password'],
            'ref' => $validated['ref'] ?? null,
            'lat' => $validated['lat'] ?? null,
            'lng' => $validated['lng'] ?? null,
        ]);

        $referrer = isset($validated['ref'])
            ? User::query()->where('public_id', $validated['ref'])->first()
            : null;

        // El registro normal ya recibe `ref` como campo oculto (ya asignado
        // dentro de RegisterUser). Esta llamada limpia el referente
        // recordado para OAuth y, si hiciera falta, lo aplica sin
        // sobrescribir la atribución que acaba de guardarse.
        $referralAttribution->attribute($request, $user);

        Auth::login($user);

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
        $referrerDriverCode = $referrer?->driverProfile?->invite_code;
        if ($referrerDriverCode) {
            return redirect()->route('referrals.show', $referrerDriverCode)
                ->with('status', '¡Cuenta creada! Ya puede agregarlo a su flota de confianza.');
        }

        return redirect(RouteServiceProvider::HOME);
    }
}
