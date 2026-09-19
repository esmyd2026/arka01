<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\RegisterUser;
use App\Exceptions\ActiveSessionExistsException;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\Cooperative;
use App\Models\User;
use App\Rules\ValidPhoneNumberLocal;
use App\Services\Auth\DriverOfflineOnLogout;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

/**
 * Login/logout de la app móvil (ROADMAP_APLICACION_MOVIL_CAPACITOR.md,
 * Hito 2) — la contraparte de AuthenticatedSessionController pero emitiendo
 * un token de Sanctum en vez de una cookie de sesión. Reusa las mismas
 * piezas que el login web en vez de reimplementar la regla:
 * User::findByLoginIdentifier() para resolver la cuenta, Auth::login() para
 * que EnforceSingleActiveSession aplique la sesión única exactamente igual
 * (ver ese listener: ahora también mira personal_access_tokens), y
 * DriverOfflineOnLogout para el mismo efecto de "conductor se desconecta al
 * cerrar sesión".
 *
 * Sin atribución de referidos acá a propósito: ReferralAttribution depende
 * de la sesión de navegador (?ref= guardado al ver la página de login), un
 * concepto que no existe en un login por token — si el registro móvil llega
 * a necesitar referidos, el código de quien invita se manda como campo del
 * propio formulario de registro, no por sesión.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly DriverOfflineOnLogout $driverOfflineOnLogout,
        private readonly RegisterUser $registerUser,
    ) {}

    /**
     * Registro móvil — reusa exactamente la misma Action que
     * RegisteredUserController::store() (web), así ninguna regla de negocio
     * (duplicados, ciudad por cercanía, verificación de teléfono por
     * WhatsApp) puede divergir entre los dos canales. También conserva los
     * tres tipos de cuenta del registro web, incluida la creación de la fila
     * inicial de una cooperativa.
     */
    public function register(Request $request): JsonResponse
    {
        $request->merge([
            'phone_local' => ValidPhoneNumberLocal::normalize($request->input('country_code'), $request->input('phone_local')),
        ]);

        $data = $request->validate([
            'account_type' => ['required', 'string', Rule::in(['cliente', 'conductor', 'cooperativa'])],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
            'country_code' => ['required', 'string', Rule::in(RegisteredUserController::COUNTRY_CODES)],
            'phone_local' => ['required', 'string', new ValidPhoneNumberLocal],
            'password' => ['required', 'confirmed', Rules\Password::defaults()->min(8)->mixedCase()->numbers()],
            'ref' => ['nullable', 'uuid', 'exists:users,public_id'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'device_id' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'platform' => ['required', 'string', 'in:android,ios'],
            'app_version' => ['nullable', 'string', 'max:32'],
        ]);

        $user = $this->registerUser->execute([
            'account_type' => $data['account_type'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'phone' => $data['country_code'].$data['phone_local'],
            'password' => $data['password'],
            'ref' => $data['ref'] ?? null,
            'lat' => $data['lat'] ?? null,
            'lng' => $data['lng'] ?? null,
        ]);

        if ($data['account_type'] === 'cooperativa') {
            Cooperative::query()->create([
                'user_id' => $user->id,
                'phone' => $user->phone,
                'email' => $user->email,
                'city_id' => $user->city_id,
            ]);
            $user->refresh();
        }

        // Una cuenta recién creada nunca puede tener otra sesión activa —
        // Auth::login() no debería tirar ActiveSessionExistsException acá,
        // pero pasa por el mismo camino que login() para que el token quede
        // registrado en `sessions`/`personal_access_tokens` exactamente igual.
        Auth::login($user);

        $token = $this->issueToken($user, $data);

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at,
            'user' => new UserResource($user),
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
            // Generado una vez por el cliente Capacitor y guardado en el
            // dispositivo (no es secreto, solo identifica "este teléfono"
            // para la regla de sesión única — ver EnforceSingleActiveSession).
            'device_id' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'platform' => ['required', 'string', 'in:android,ios'],
            'app_version' => ['nullable', 'string', 'max:32'],
        ]);

        $this->ensureIsNotRateLimited($request);

        $user = User::findByLoginIdentifier($data['login']);

        if ($user?->isLocked()) {
            throw ValidationException::withMessages([
                'login' => 'Esta cuenta está bloqueada por seguridad. Contáctenos para reactivarla.',
            ]);
        }

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($this->throttleKey($request));

            throw ValidationException::withMessages([
                'login' => $user
                    ? trans('auth.failed')
                    : 'No encontramos una cuenta con ese dato. ¿Quiere crear una cuenta?',
            ]);
        }

        try {
            Auth::login($user);
        } catch (ActiveSessionExistsException $e) {
            throw ValidationException::withMessages(['login' => $e->getMessage()]);
        }

        RateLimiter::clear($this->throttleKey($request));

        $token = $this->issueToken($user, $data);

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at,
            'user' => new UserResource($user),
        ]);
    }

    public function exchangeGoogleCode(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'size:64']]);
        $mobile = Cache::pull('mobile-google-auth:'.$data['code']);

        if (! is_array($mobile) || ! isset($mobile['user_id'], $mobile['device_id'], $mobile['platform'])) {
            throw ValidationException::withMessages(['code' => 'El acceso con Google expiró. Inténtalo nuevamente.']);
        }

        $user = User::query()->findOrFail($mobile['user_id']);
        if ($user->isLocked()) {
            throw ValidationException::withMessages(['code' => 'Esta cuenta está bloqueada por seguridad.']);
        }

        // El listener de sesión única lee device_id del request de login.
        $request->merge(['device_id' => $mobile['device_id']]);

        try {
            Auth::login($user);
        } catch (ActiveSessionExistsException $e) {
            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }

        $token = $this->issueToken($user, $mobile);

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at,
            'user' => new UserResource($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->driverOfflineOnLogout->handle($request->user());

        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    public function me(Request $request): JsonResponse
    {
        // response()->json() en vez de retornar el Resource directo: así no
        // queda envuelto en {"data": ...} (comportamiento por defecto de
        // JsonResource cuando es la respuesta de la ruta) y el objeto
        // "usuario" tiene la misma forma acá que dentro de login().
        return response()->json(new UserResource($request->user()));
    }

    /**
     * @param  array{device_id: string, device_name?: ?string, platform: string, app_version?: ?string}  $data
     */
    private function issueToken(User $user, array $data): NewAccessToken
    {
        $token = $user->createToken(
            // 'nullable' en las reglas de arriba: si no viene, la key ni
            // existe en $data (así valida Laravel un campo ausente y
            // nullable) — de ahí el ?? antes del ?:, no alcanza con uno solo.
            ($data['device_name'] ?? null) ?: $data['platform'],
            ['*'],
            now()->addDays((int) config('mobile.token_ttl_days')),
        );

        $token->accessToken->forceFill([
            'device_id' => $data['device_id'],
            'platform' => $data['platform'],
            'app_version' => $data['app_version'] ?? null,
        ])->save();

        return $token;
    }

    private function ensureIsNotRateLimited(Request $request): void
    {
        $key = $this->throttleKey($request);

        if (! RateLimiter::tooManyAttempts($key, 5)) {
            return;
        }

        event(new Lockout($request));

        $seconds = RateLimiter::availableIn($key);

        throw ValidationException::withMessages([
            'login' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    private function throttleKey(Request $request): string
    {
        return Str::transliterate(Str::lower((string) $request->string('login')).'|'.$request->ip());
    }
}
