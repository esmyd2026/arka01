<?php

namespace Tests\Feature\Admin;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Configuración del sitio público (pedido explícito del usuario: "por lo
 * menos haz que la pueda colocar desde la parte de configuración del
 * admin" — la imagen de fondo del hero de Welcome.vue, en vez de depender
 * de copiarla a mano a public/img/).
 */
class AdminSiteSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_regular_user_cannot_access_the_site_settings(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.site.edit'))->assertForbidden();
    }

    public function test_an_admin_can_upload_a_hero_background(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.site.update'), [
            'hero_background' => UploadedFile::fake()->image('fondo.jpg'),
        ])->assertRedirect();

        $setting = SiteSetting::current();
        $this->assertNotNull($setting->hero_background_path);
        Storage::disk('public')->assertExists($setting->hero_background_path);
        $this->assertSame($admin->id, $setting->updated_by);
    }

    public function test_uploading_a_new_background_replaces_and_deletes_the_old_file(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.site.update'), [
            'hero_background' => UploadedFile::fake()->image('primero.jpg'),
        ]);
        $firstPath = SiteSetting::current()->hero_background_path;

        $this->actingAs($admin)->post(route('admin.site.update'), [
            'hero_background' => UploadedFile::fake()->image('segundo.jpg'),
        ]);
        $secondPath = SiteSetting::current()->hero_background_path;

        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);
    }

    public function test_an_admin_can_remove_the_hero_background(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->post(route('admin.site.update'), [
            'hero_background' => UploadedFile::fake()->image('fondo.jpg'),
        ]);
        $path = SiteSetting::current()->hero_background_path;

        $this->actingAs($admin)->post(route('admin.site.update'), [
            'remove_hero_background' => true,
        ])->assertRedirect();

        $this->assertNull(SiteSetting::current()->hero_background_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_a_non_image_file_is_rejected(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.site.update'), [
            'hero_background' => UploadedFile::fake()->create('documento.pdf', 100, 'application/pdf'),
        ])->assertSessionHasErrors('hero_background');
    }

    public function test_the_public_homepage_exposes_the_configured_background_url(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->post(route('admin.site.update'), [
            'hero_background' => UploadedFile::fake()->image('fondo.jpg'),
        ]);
        $path = SiteSetting::current()->hero_background_path;

        $response = $this->get('/');

        $response->assertInertia(fn ($page) => $page
            ->where('heroBackgroundUrl', Storage::disk('public')->url($path))
        );
    }
}
