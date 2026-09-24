<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\ActiveSessionExistsException;
use App\Http\Controllers\Controller;
use App\Models\Cooperative;
use App\Models\User;
use App\Services\ReferralAttribution;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
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

    public function redirect(Request $request): RedirectResponse
    {
        if (! $request->boolean('mobile')) {
            return Socialite::driver('google')->redirect();
        }

        $mobile = $request->validate([
            'device_id' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'string', 'in:android,ios'],
            'account_type' => ['nullable', 'string', 'in:cliente,conductor,cooperativa'],
        ]);

        // Bug real reportado por el usuario ("le doy [a Continuar con
        // Google] y me lleva a la web de arka01, y cuando voy a la app se
        // queda ahí cargando"): la versión anterior guardaba $mobile en
        // caché bajo una clave derivada del `state` random de Socialite, y
        // callback() lo recuperaba con Cache::pull() al volver de Google —
        // en teoría alcanzaba, pero dependía de que ese valor siguiera
        // disponible en el mismo caché al volver (vencimiento, un despliegue
        // en el medio, más de un proceso/servidor sirviendo la app sin
        // caché compartido). Cualquier fallo ahí hacía que callback() nunca
        // se enterara de que este login era desde la app — terminaba
        // logueando al usuario en la web normal (adentro del navegador
        // embebido) en vez de devolverlo a la app con su código, que es
        // exactamente el síntoma reportado: la app se queda con el spinner
        // girando para siempre porque el esquema `com.arka01.app://` nunca
        // vuelve.
        //
        // Ahora $mobile viaja cifrado DENTRO del propio `state` que Google
        // manda de vuelta intacto — así es como funciona el protocolo
        // siempre, sin depender de que sobreviva nada del lado del
        // servidor entre la ida y la vuelta. `stateless()` evita que
        // Socialite intente además comparar este `state` contra la sesión
        // (que Chrome Custom Tabs puede devolver con una cookie distinta).
        return Socialite::driver('google')
            ->stateless()
            ->with(['state' => encrypt(['mobile' => $mobile])])
            ->redirect();
    }

    public function callback(Request $request): RedirectResponse|View
    {
        $mobileFromState = $this->decodeMobileState($request->string('state')->toString());

        try {
            $googleProvider = Socialite::driver('google');
            // Solo se omite la comprobación de sesión cuando el state trae un
            // payload móvil válido (ver decodeMobileState()) — Google igual
            // valida el authorization code y devuelve la identidad real.
            $googleUser = is_array($mobileFromState)
                ? $googleProvider->stateless()->user()
                : $googleProvider->user();
        } catch (InvalidStateException) {
            // El usuario volvió con un link viejo o expirado — se lo manda
            // de nuevo al login en vez de mostrarle un error críptico.
            return $this->failedGoogleAuth($mobileFromState, 'El enlace de Google expiró, pruebe de nuevo.');
        } catch (ClientException $e) {
            // Error real visto en producción (log de errores del admin):
            // Google responde 400 "invalid_grant" al canjear el
            // authorization code — pasa cuando ese código ya se usó (el
            // usuario volvió atrás y recargó el link de callback, o el
            // navegador reintentó la petición) o cuando expiró por tardar
            // demasiado en la pantalla de consentimiento. Antes esto
            // quedaba sin capturar y explotaba como excepción crítica sin
            // manejar; ahora se trata igual que un enlace vencido.
            report($e);

            return $this->failedGoogleAuth($mobileFromState, 'El enlace de Google ya se usó o expiró, pruebe de nuevo.');
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
            // profile_name_completed_at: Google ya trae un nombre real, no
            // pasa por la pantalla de completar nombre del registro rápido.
            $user->forceFill(['email_verified_at' => now(), 'profile_name_completed_at' => now()])->save();
        }

        if (is_array($mobileFromState)) {
            $mobile = $mobileFromState;

            if ($isNewUser && ($mobile['account_type'] ?? null) === 'conductor') {
                $user->forceFill(['intends_to_drive' => true])->save();
            }

            if ($isNewUser && ($mobile['account_type'] ?? null) === 'cooperativa') {
                Cooperative::query()->create([
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'city_id' => $user->city_id,
                ]);
            }

            $this->referralAttribution->attribute($request, $user);

            // El URI personalizado nunca lleva un token de Sanctum. Lleva
            // un código de un solo uso y corta duración que la app canjea.
            $code = Str::random(64);
            Cache::put('mobile-google-auth:'.$code, [
                'user_id' => $user->id,
                'device_id' => $mobile['device_id'],
                'platform' => $mobile['platform'],
            ], now()->addMinutes(2));

            return $this->mobileReturn('com.arka01.app://auth/google?code='.urlencode($code));
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
            // Nota: este catch es solo para el flujo web — el móvil ya
            // devolvió más arriba con su propio código de un solo uso antes
            // de llegar acá, nunca pasa por Auth::login().
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

    /**
     * Un fallo antes de resolver la identidad de Google (estado vencido o
     * el authorization code rechazado) no sabe todavía si esto era un login
     * de la app o de la web. Bug real reportado por el usuario: para móvil,
     * mandar siempre a la pantalla web de login dejaba el botón "Continuar
     * con Google" de la app girando para siempre — el Custom Tab mostraba
     * el mensaje, pero la app nunca recuperaba el control porque jamás
     * volvía el esquema `com.arka01.app://`. `$mobileFromState` alcanza acá
     * ya descifrado del propio `state` (ver decodeMobileState()) porque el
     * flujo móvil siempre usa `stateless()`, así que Socialite nunca
     * depende de la sesión para validarlo.
     */
    private function failedGoogleAuth(?array $mobileFromState, string $message): RedirectResponse|View
    {
        if (is_array($mobileFromState)) {
            return $this->mobileReturn('com.arka01.app://auth/google?error='.urlencode($message));
        }

        return redirect()->route('login')->with('status', $message);
    }

    /**
     * Bug real reportado por el usuario ("la app se queda cargando... nunca
     * entra"): antes esto era `redirect()->away($url)`, una redirección HTTP
     * directa al esquema personalizado `com.arka01.app://`. Android/Chrome
     * Custom Tabs puede negarse a entregarle el control a la app cuando esa
     * navegación llega como una redirección DEL SERVIDOR en vez de como una
     * acción disparada desde dentro de la propia página — sin aviso, se
     * queda mostrando la pestaña quieta y la app nunca se entera de nada.
     * Una página intermedia que navega por JavaScript (y, si el navegador la
     * bloquea igual, ofrece un botón para un toque real) es el patrón que sí
     * funciona de forma consistente para este caso — ver
     * resources/views/auth/mobile-return.blade.php.
     */
    private function mobileReturn(string $url): View
    {
        return view('auth.mobile-return', ['url' => $url]);
    }

    /**
     * Descifra el payload móvil embebido en `state` por redirect() (ver ahí
     * el porqué). Un `state` de un login web normal — el random de
     * Socialite, sin cifrar — simplemente no descifra: DecryptException se
     * trata como "esto no es un login móvil", no como un error real.
     *
     * @return array{device_id: string, platform: string, account_type: ?string}|null
     */
    private function decodeMobileState(string $state): ?array
    {
        if ($state === '') {
            return null;
        }

        try {
            $payload = decrypt($state);
        } catch (DecryptException) {
            return null;
        }

        return is_array($payload) && is_array($payload['mobile'] ?? null) ? $payload['mobile'] : null;
    }
}
