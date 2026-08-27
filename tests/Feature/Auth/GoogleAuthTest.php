<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $provider->shouldReceive('user')->andReturn($socialiteUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_redirect_sends_the_visitor_to_google(): void
    {
        $response = $this->get(route('auth.google.redirect'));

        $response->assertRedirect();
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

        $this->get(route('login', ['ref' => $referrer->id]))->assertOk();
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
}
