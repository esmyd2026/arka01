<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

/**
 * "Iniciar sesión con Google" (Socialite): alternativa al login con usuario
 * y contraseña, no lo reemplaza. Cubre alta de cuenta nueva, linkeo de una
 * cuenta que ya existía por email, y que un enlace vencido no rompa la app.
 */
class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGoogleUser(string $id, string $email, string $name, string $avatar = 'https://example.com/avatar.jpg'): void
    {
        $socialiteUser = Mockery::mock(SocialiteUser::class);
        $socialiteUser->shouldReceive('getId')->andReturn($id);
        $socialiteUser->shouldReceive('getEmail')->andReturn($email);
        $socialiteUser->shouldReceive('getName')->andReturn($name);
        $socialiteUser->shouldReceive('getAvatar')->andReturn($avatar);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('stateless')->zeroOrMoreTimes()->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($socialiteUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    /**
     * La vuelta a la app para un login móvil ya no es una redirección HTTP
     * directa al esquema `com.arka01.app://` (ver GoogleAuthController::
     * mobileReturn() y su docblock: Android/Chrome Custom Tabs puede
     * ignorarla en silencio) — ahora es una página HTML que navega por
     * JavaScript. Esto extrae esa URL del `<script>` embebido para poder
     * seguir probando el código/error que trae, igual que antes.
     */
    private function extractMobileReturnUrl($response): string
    {
        preg_match('/window\.location\.href\s*=\s*(".*?");/', $response->getContent(), $matches);
        $this->assertNotEmpty($matches, 'No se encontró la URL de retorno en la página intermedia.');

        return json_decode($matches[1]);
    }

    public function test_redirect_sends_the_visitor_to_google(): void
    {
        $response = $this->get(route('auth.google.redirect'));

        $response->assertRedirect();
    }

    /**
     * Error real visto en producción (log de errores del admin): Google
     * responde 400 "invalid_grant" al canjear el authorization code —
     * código ya usado (el usuario volvió atrás y recargó el link de
     * callback) o vencido por tardar en la pantalla de consentimiento.
     * Antes quedaba sin capturar y explotaba como excepción crítica; ahora
     * se trata igual que un enlace vencido, sin crear ninguna cuenta.
     */
    public function test_an_expired_or_reused_google_code_shows_a_friendly_message_instead_of_crashing(): void
    {
        $request = new GuzzleRequest('POST', 'https://www.googleapis.com/oauth2/v4/token');
        $response = new GuzzleResponse(400, [], json_encode(['error' => 'invalid_grant', 'error_description' => 'Bad Request']));

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('stateless')->zeroOrMoreTimes()->andReturnSelf();
        $provider->shouldReceive('user')->andThrow(new ClientException('Bad Request', $request, $response));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $callbackResponse = $this->get(route('auth.google.callback'));

        $callbackResponse->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertSame(0, User::query()->count());
    }

    /**
     * Bug real reportado por el usuario (captura del botón "Continuar con
     * Google" de la app girando para siempre): si esto pasaba durante un
     * login MÓVIL, la línea de arriba mandaba igual a la pantalla web de
     * login — el Custom Tab mostraba el mensaje, pero la app nunca
     * recuperaba el control porque jamás volvía el esquema
     * `com.arka01.app://`, dejando el spinner pegado para siempre. Ahora
     * vuelve por ese mismo esquema con `error=`, que Login.vue/Register.vue
     * ya saben apagar el spinner y mostrar.
     */
    public function test_an_expired_or_reused_google_code_during_a_mobile_login_returns_to_the_app_with_an_error(): void
    {
        $redirect = $this->get(route('auth.google.redirect', [
            'mobile' => 1,
            'device_id' => 'pixel-test-device',
            'platform' => 'android',
        ]));
        parse_str(parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $googleQuery);

        $request = new GuzzleRequest('POST', 'https://www.googleapis.com/oauth2/v4/token');
        $response = new GuzzleResponse(400, [], json_encode(['error' => 'invalid_grant']));

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('stateless')->zeroOrMoreTimes()->andReturnSelf();
        $provider->shouldReceive('user')->andThrow(new ClientException('Bad Request', $request, $response));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $callbackResponse = $this->get(route('auth.google.callback', ['state' => $googleQuery['state']]));

        $callbackResponse->assertOk();
        $this->assertStringStartsWith('com.arka01.app://auth/google?error=', $this->extractMobileReturnUrl($callbackResponse));
        $this->assertGuest();
        $this->assertSame(0, User::query()->count());
    }

    /**
     * Pedido explícito del usuario: una cuenta de Google nueva no puede
     * quedar como "cliente" en silencio — se manda a elegir tipo de cuenta,
     * mismo primer paso que ya exige el registro normal (Register.vue).
     */
    public function test_callback_creates_a_new_account_and_sends_it_to_choose_account_type(): void
    {
        $this->fakeGoogleUser('google-123', 'nuevo@example.com', 'Nueva Persona');

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('account-type.choose'));
        $this->assertAuthenticated();

        $user = User::query()->where('email', 'nuevo@example.com')->firstOrFail();
        $this->assertSame('google-123', $user->google_id);
        $this->assertSame('Nueva Persona', $user->name);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_the_choose_account_type_screen_is_reachable_after_a_new_google_signup(): void
    {
        $this->fakeGoogleUser('google-999', 'flamante@example.com', 'Flamante');
        $this->get(route('auth.google.callback'));

        $this->get(route('account-type.choose'))->assertOk();
    }

    public function test_callback_links_an_existing_account_found_by_email(): void
    {
        $existing = User::factory()->create([
            'email' => 'ya-existe@example.com',
            'google_id' => null,
        ]);

        $this->fakeGoogleUser('google-456', 'ya-existe@example.com', 'Ya Existe');

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($existing->fresh());

        $this->assertSame(1, User::where('email', 'ya-existe@example.com')->count());
        $this->assertSame('google-456', $existing->fresh()->google_id);
    }

    public function test_google_login_preserves_the_referrer_from_a_public_profile(): void
    {
        $referrer = User::factory()->create();

        $this->get(route('login', ['ref' => $referrer->public_id]))->assertOk();
        $this->fakeGoogleUser('google-referral', 'referido-google@example.com', 'Referido Google');

        $this->get(route('auth.google.callback'))->assertRedirect(route('account-type.choose'));

        $referredUser = User::query()->where('email', 'referido-google@example.com')->firstOrFail();
        $this->assertSame($referrer->id, $referredUser->referred_by_user_id);
    }

    public function test_callback_reuses_the_same_account_on_a_second_login(): void
    {
        $this->fakeGoogleUser('google-789', 'repetido@example.com', 'Repetido');
        $this->get(route('auth.google.callback'));
        $firstUserId = User::where('email', 'repetido@example.com')->value('id');

        // Simula que cerró sesión en el primer dispositivo antes de volver a
        // entrar: si la fila de `sessions` del primer login siguiera activa,
        // la sesión única por cuenta (EnforceSingleActiveSession) bloquearía
        // este segundo login más abajo.
        DB::table('sessions')->where('user_id', $firstUserId)->delete();

        $this->fakeGoogleUser('google-789', 'repetido@example.com', 'Repetido');
        $this->get(route('auth.google.callback'));

        $this->assertSame(1, User::where('email', 'repetido@example.com')->count());
        $this->assertAuthenticatedAs(User::find($firstUserId));
    }

    public function test_mobile_google_callback_returns_a_one_time_code_that_the_app_can_exchange(): void
    {
        $redirect = $this->get(route('auth.google.redirect', [
            'mobile' => 1,
            'device_id' => 'pixel-test-device',
            'platform' => 'android',
            'account_type' => 'cliente',
        ]));
        parse_str(parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $googleQuery);

        $this->fakeGoogleUser('google-mobile', 'mobile@example.com', 'Cuenta Móvil');

        $callback = $this->get(route('auth.google.callback', ['state' => $googleQuery['state']]));

        $callback->assertOk();
        $location = $this->extractMobileReturnUrl($callback);
        $this->assertStringStartsWith('com.arka01.app://auth/google?code=', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $query);

        $exchange = $this->postJson(route('api.v1.auth.google.exchange'), ['code' => $query['code']]);

        $exchange->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => User::where('email', 'mobile@example.com')->value('id'),
            'device_id' => 'pixel-test-device',
            'platform' => 'android',
        ]);

        $this->postJson(route('api.v1.auth.google.exchange'), ['code' => $query['code']])->assertUnprocessable();
    }

    /**
     * Pedido explícito del usuario ("sigue... me lleva a la web de arka01 y
     * se queda ahí cargando"): antes los datos móviles se guardaban en un
     * caché server-side asociado al `state`, y una sesión de Chrome Custom
     * Tabs distinta a la del navegador que arrancó el flujo ya se sabía que
     * podía perderse. Ahora los datos móviles viajan cifrados DENTRO del
     * propio `state` que Google siempre devuelve intacto (así funciona el
     * protocolo) — perder la sesión ya no puede romper nada de esto.
     */
    public function test_mobile_google_callback_survives_a_lost_browser_session(): void
    {
        $redirect = $this->get(route('auth.google.redirect', [
            'mobile' => 1,
            'device_id' => 'pixel-lost-cookie',
            'platform' => 'android',
            'account_type' => 'cliente',
        ]));

        parse_str(parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $googleQuery);
        $this->assertNotEmpty($googleQuery['state'] ?? null);

        Session::flush();
        $this->fakeGoogleUser('google-mobile-state', 'mobile-state@example.com', 'Cuenta Móvil State');

        $callback = $this->get(route('auth.google.callback', ['state' => $googleQuery['state']]));

        $callback->assertOk();
        $this->assertStringStartsWith('com.arka01.app://auth/google?code=', $this->extractMobileReturnUrl($callback));
    }

    /**
     * Regresión directa del bug reportado: el mecanismo viejo dependía de
     * que un valor guardado en caché durante redirect() todavía estuviera
     * ahí al volver de Google — un cache:clear, un redeploy, o más de un
     * servidor sin caché compartido en el medio rompía el login móvil sin
     * avisar (terminaba logueando al usuario en la web normal, adentro del
     * navegador embebido, en vez de devolverlo a la app). Ahora no depende
     * de nada guardado del lado del servidor entre las dos puntas.
     */
    public function test_mobile_google_callback_survives_the_cache_being_cleared(): void
    {
        $redirect = $this->get(route('auth.google.redirect', [
            'mobile' => 1,
            'device_id' => 'pixel-cache-cleared',
            'platform' => 'android',
        ]));
        parse_str(parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $googleQuery);

        Cache::flush();
        $this->fakeGoogleUser('google-cache-cleared', 'cache-cleared@example.com', 'Cache Limpio');

        $callback = $this->get(route('auth.google.callback', ['state' => $googleQuery['state']]));

        $callback->assertOk();
        $this->assertStringStartsWith('com.arka01.app://auth/google?code=', $this->extractMobileReturnUrl($callback));
    }
}
