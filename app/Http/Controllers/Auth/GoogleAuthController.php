<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\ActiveSessionExistsException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ReferralAttribution;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

/**
 * "Iniciar sesión con Google" (Socialite / OAuth): alternativa al login con
 * usuario y contraseña de siempre, no lo reemplaza. Si ya existía una cuenta
 * con ese correo (ej. una de las cuentas de demo) se linkea sola; si no,
 * se crea una cuenta nueva.
 */
class GoogleAuthController extends Controller
{
    public function __construct(private readonly ReferralAttribution $referralAttribution) {}

    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (InvalidStateException) {
            // El usuario volvió con un link viejo o expirado — se lo manda
            // de nuevo al login en vez de mostrarle un error críptico.
            return redirect()->route('login')->with('status', 'El enlace de Google expiró, pruebe de nuevo.');
        }

        $user = User::query()->where('google_id', $googleUser->getId())->first()
            ?? User::query()->where('email', $googleUser->getEmail())->first();

        // Bloqueo de cuenta (pedido explícito del usuario): mismo chequeo
        // que el login con contraseña (App\Http\Requests\Auth\LoginRequest)
        // — Google no pasa por ahí, necesita su propia verificación.
        if ($user?->isLocked()) {
            return redirect()->route('login')->with('status', 'Esta cuenta está bloqueada por seguridad. Contáctenos para reactivarla.');
        }

        // Pedido explícito del usuario: una cuenta de Google NUEVA (ni por
        // google_id ni por email) no tiene que quedar como "cliente" en
        // silencio — se manda a elegir tipo de cuenta (ver el redirect de
        // abajo), mismo primer paso que ya exige el registro normal. Una
        // cuenta que YA EXISTÍA (aunque recién ahora se linkee con Google)
        // sigue directo a Inicio, como siempre — ya tiene un rol elegido.
        $isNewUser = $user === null;

        if ($user) {
            // Cuenta que ya existía por email (ej. una de demo) pero todavía
            // no tenía Google linkeado — queda linkeada a partir de ahora.
            if (! $user->google_id) {
                $user->forceFill([
                    'google_id' => $googleUser->getId(),
                    'avatar_path' => $user->avatar_path ?? $googleUser->getAvatar(),
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->save();
            }
        } else {
            $user = User::query()->create([
                'name' => $googleUser->getName() ?: explode('@', $googleUser->getEmail())[0],
                'email' => $googleUser->getEmail(),
                'google_id' => $googleUser->getId(),
                'avatar_path' => $googleUser->getAvatar(),
                // Nunca se usa para entrar (siempre va a ser por Google), pero
                // la columna es obligatoria — una al azar que nadie conoce.
                'password' => Hash::make(Str::random(40)),
            ]);

            // email_verified_at no es mass-assignable a propósito (no debería
            // poder mandarse desde un formulario cualquiera), así que se marca
            // aparte: Google ya verificó ese correo por su cuenta.
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        // Sin "recordarme" (ver App\Listeners\EnforceSingleActiveSession): la
        // sesión única por cuenta necesita que todo login pase por acá.
        try {
            Auth::login($user);
        } catch (ActiveSessionExistsException $e) {
            // Bug reportado por el usuario: el widget de "pedir código por
            // WhatsApp para cerrar la otra sesión" (Auth/Login.vue) solo
            // reaccionaba al error de formulario del login con contraseña
            // (form.errors.login) — acá no hay ningún formulario, así que el
            // mensaje aparecía pero el botón de pedir código no. Se manda el
            // mismo mensaje por 'status' (que Login.vue ya sabía mostrar) más
            // el correo en 'login_hint', para que el widget sepa a qué cuenta
            // pedirle el código sin que el usuario tenga que volver a escribirlo.
            return redirect()->route('login')
                ->with('status', $e->getMessage())
                ->with('login_hint', $user->email);
        }

        $this->referralAttribution->attribute($request, $user);

        if ($isNewUser) {
            return redirect()->route('account-type.choose');
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
