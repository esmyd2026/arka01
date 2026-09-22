<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\ActiveSessionExistsException;
use App\Http\Controllers\Controller;
use App\Models\Cooperative;
use App\Models\User;
use App\Services\ReferralAttribution;
use GuzzleHttp\Exception\ClientException;
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
        $mobile = null;

        if ($request->boolean('mobile')) {
            $mobile = $request->validate([
                'device_id' => ['required', 'string', 'max:255'],
                'platform' => ['required', 'string', 'in:android,ios'],
                'account_type' => ['nullable', 'string', 'in:cliente,conductor,cooperativa'],
            ]);

            // La sesión del navegador solo transporta datos no secretos del
            // dispositivo durante el ida y vuelta de Google.
            $request->session()->put('mobile_google_auth', $mobile);
        }

        $redirect = Socialite::driver('google')->redirect();

        if (is_array($mobile)) {
            parse_str((string) parse_url($redirect->getTargetUrl(), PHP_URL_QUERY), $googleQuery);
            $state = $googleQuery['state'] ?? null;

            if (is_string($state) && $state !== '') {
                // Chrome Custom Tabs puede volver con una cookie de sesión
                // distinta. El state aleatorio generado por Socialite permite
                // conservar el contexto móvil sin poner device_id en la URL de
                // callback ni convertir un acceso web normal en móvil.
                Cache::put($this->mobileStateCacheKey($state), $mobile, now()->addMinutes(10));
            }
        }

        return $redirect;
    }

    public function callback(Request $request): RedirectResponse
    {
        $oauthState = $request->string('state')->toString();
        $mobileFromState = $oauthState !== ''
            ? Cache::pull($this->mobileStateCacheKey($oauthState))
            : null;

        try {
            $googleProvider = Socialite::driver('google');
            // Solo se omite la comprobación de sesión cuando el state existe
            // en nuestra caché de un flujo móvil iniciado por este servidor.
            // Google igual valida el authorization code y devuelve la identidad.
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

        $mobileFromSession = $request->session()->pull('mobile_google_auth');
        $mobile = is_array($mobileFromState) ? $mobileFromState : $mobileFromSession;
        if (is_array($mobile)) {
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

            return redirect()->away('com.arka01.app://auth/google?code='.urlencode($code));
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
     * (viene del caché por `state`, no de la sesión — la sesión del Custom
     * Tab puede volver distinta, por eso existe ese caché) porque el flujo
     * móvil siempre usa `stateless()`, así que Socialite nunca depende de
     * la sesión para validarlo.
     */
    private function failedGoogleAuth(?array $mobileFromState, string $message): RedirectResponse
    {
        if (is_array($mobileFromState)) {
            return redirect()->away('com.arka01.app://auth/google?error='.urlencode($message));
        }

        return redirect()->route('login')->with('status', $message);
    }

    private function mobileStateCacheKey(string $state): string
    {
        return 'mobile-google-oauth-state:'.hash('sha256', $state);
    }
}
