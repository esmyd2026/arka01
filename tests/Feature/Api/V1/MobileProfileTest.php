<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Editar los datos personales del usuario desde la app móvil (roadmap
 * Hito 5B, paridad con la web). Reusa App\Services\Profile\ProfileUpdater,
 * la misma lógica que la web (tests\Feature\ProfileTest,
 * tests\Feature\ProfilePhoneUpdateTest) — estos casos se enfocan en el
 * contrato JSON del canal móvil.
 */
class MobileProfileTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/v1/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response->assertOk()->assertJsonPath('user.name', 'Test User');

        $user->refresh();
        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_a_user_can_upload_a_profile_photo(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->post('/api/v1/profile', [
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => UploadedFile::fake()->image('foto.jpg'),
            ]);

        $response->assertOk();

        $user->refresh();
        Storage::disk('public')->assertExists($user->avatar_path);
    }

    public function test_a_birth_date_under_18_years_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/v1/profile', [
                'name' => $user->name,
                'email' => $user->email,
                'birth_date' => now()->subYears(10)->toDateString(),
            ])
            ->assertUnprocessable();
    }

    public function test_a_client_can_update_their_phone_number(): void
    {
        $user = User::factory()->create(['phone' => '+593991111111']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/v1/profile', [
                'name' => $user->name,
                'email' => $user->email,
                'country_code' => '+593',
                'phone_local' => '992222222',
            ])
            ->assertOk();

        $this->assertSame('+593992222222', $user->fresh()->phone);
    }

    public function test_can_search_for_a_referrer_by_partial_name(): void
    {
        $user = User::factory()->create();
        $referrer = User::factory()->create(['name' => 'Ana Referidora']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/v1/profile/search-referrer?q=Ana');

        $response->assertOk();
        $this->assertTrue(collect($response->json('users'))->pluck('id')->contains($referrer->id));
    }

    public function test_can_save_who_referred_them(): void
    {
        $user = User::factory()->create();
        $referrer = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/v1/profile/referrer', ['referrer_user_id' => $referrer->id])
            ->assertOk();

        $this->assertSame($referrer->id, $user->fresh()->referred_by_user_id);
    }

    public function test_cannot_overwrite_an_already_saved_referrer(): void
    {
        $referrer = User::factory()->create();
        $user = User::factory()->create(['referred_by_user_id' => $referrer->id]);
        $another = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/v1/profile/referrer', ['referrer_user_id' => $another->id])
            ->assertUnprocessable();

        $this->assertSame($referrer->id, $user->fresh()->referred_by_user_id);
    }

    public function test_it_requires_a_token(): void
    {
        $this->postJson('/api/v1/profile', ['name' => 'x', 'email' => 'x@example.com'])->assertUnauthorized();
    }

    public function test_password_can_be_updated_from_the_mobile_api(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password'), 'password_set_at' => now()]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->putJson('/api/v1/profile/password', [
                'current_password' => 'old-password',
                'password' => 'New-password-123',
                'password_confirmation' => 'New-password-123',
            ])->assertOk();

        $this->assertTrue(Hash::check('New-password-123', $user->fresh()->password));
    }
}
