<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertTrue(Hash::check('new-password', $user->refresh()->password));
    }

    public function test_correct_password_must_be_provided_to_update_password(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response
            ->assertSessionHasErrors('current_password')
            ->assertRedirect('/profile');
    }

    /**
     * Bug real reportado por el usuario: una cuenta que entró por Google
     * tiene una contraseña al azar que nadie conoce (ver GoogleAuthController)
     * — pedirle "la actual" la dejaba bloqueada para siempre. Acá puede
     * crear la suya sin que se la pidan.
     */
    public function test_a_google_account_without_its_own_password_can_create_one_without_the_current_password(): void
    {
        $user = User::factory()->create(['google_id' => '123456', 'password_set_at' => null]);

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();
        $this->assertTrue(Hash::check('new-password', $user->password));
        $this->assertNotNull($user->password_set_at);
    }

    /**
     * Una vez que la cuenta de Google ya creó su propia contraseña, vuelve
     * a exigirle la actual para cambiarla de nuevo — como cualquier otra cuenta.
     */
    public function test_a_google_account_that_already_set_its_own_password_must_provide_it_to_change_it_again(): void
    {
        $user = User::factory()->create([
            'google_id' => '123456',
            'password' => Hash::make('my-own-password'),
            'password_set_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response->assertSessionHasErrors('current_password');
    }
}
