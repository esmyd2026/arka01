<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * Sesión única por cuenta (consideración de seguridad agregada al alcance):
 * no se puede entrar desde un segundo dispositivo mientras la primera sesión
 * sigue activa, para que una cuenta no quede compartida sin control de quién
 * administra la flota en cada momento — aplica a login normal y con Google.
 */
class SingleActiveSessionTest extends TestCase
{
    use RefreshDatabase;

    private function fakeOtherDeviceSession(User $user, int $minutesAgo = 1): void
    {
        DB::table('sessions')->insert([
            'id' => Str::random(40),
            'user_id' => $user->id,
            'ip_address' => '10.0.0.9',
            'user_agent' => 'otro-dispositivo',
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->subMinutes($minutesAgo)->getTimestamp(),
        ]);
    }

    public function test_login_is_blocked_while_another_session_is_still_active(): void
    {
        $user = User::factory()->create();
        $this->fakeOtherDeviceSession($user);

        $response = $this->post('/login', [
            'login' => $user->email,
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    /**
     * Si el correo de aviso no se puede mandar (ej. el servidor de correo local
     * no está levantado), el login igual tiene que quedar bloqueado con el
     * mensaje claro — no un error 500 por un problema de infraestructura de
     * correo ajeno a la regla de sesión única.
     */
    public function test_login_is_still_blocked_even_if_the_warning_email_fails_to_send(): void
    {
        $user = User::factory()->create();
        $this->fakeOtherDeviceSession($user);

        Mail::shouldReceive('to->send')->once()->andThrow(new TransportException('mailpit unreachable'));

        $response = $this->post('/login', [
            'login' => $user->email,
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_login_is_allowed_again_once_the_other_session_expired(): void
    {
        $user = User::factory()->create();
        $this->fakeOtherDeviceSession($user, minutesAgo: (int) config('session.lifetime') + 10);

        $this->post('/login', [
            'login' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
    }

    public function test_google_login_is_also_blocked_while_another_session_is_active(): void
    {
        $user = User::factory()->create(['google_id' => 'google-999']);
        $this->fakeOtherDeviceSession($user);

        $socialiteUser = Mockery::mock(SocialiteUser::class);
        $socialiteUser->shouldReceive('getId')->andReturn('google-999');
        $socialiteUser->shouldReceive('getEmail')->andReturn($user->email);
        $socialiteUser->shouldReceive('getName')->andReturn($user->name);
        $socialiteUser->shouldReceive('getAvatar')->andReturn(null);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($socialiteUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /**
     * Bug reportado por el usuario: al volver de un login con Google
     * bloqueado por sesión única, el widget de "pedir código por WhatsApp"
     * (Auth/Login.vue) no reaccionaba — solo miraba el error de formulario
     * del login con contraseña, y este camino no tiene formulario. Necesita
     * 'status' (el mensaje, que ya se mostraba) Y 'login_hint' (el correo,
     * para saber a qué cuenta pedirle el código sin que el usuario lo
     * reescriba — a diferencia del camino de contraseña, acá nunca lo tipeó).
     */
    public function test_google_login_blocked_by_active_session_hints_the_login_widget(): void
    {
        $user = User::factory()->create(['google_id' => 'google-999']);
        $this->fakeOtherDeviceSession($user);

        $socialiteUser = Mockery::mock(SocialiteUser::class);
        $socialiteUser->shouldReceive('getId')->andReturn('google-999');
        $socialiteUser->shouldReceive('getEmail')->andReturn($user->email);
        $socialiteUser->shouldReceive('getName')->andReturn($user->name);
        $socialiteUser->shouldReceive('getAvatar')->andReturn(null);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($socialiteUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->get(route('auth.google.callback'));

        $response->assertSessionHas('status', 'Ya tiene una sesión activa en otro dispositivo. Cierre sesión ahí para poder entrar acá.');
        $response->assertSessionHas('login_hint', $user->email);
    }
}
