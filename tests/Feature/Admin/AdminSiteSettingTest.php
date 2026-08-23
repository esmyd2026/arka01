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

    // Fondo del panel de marca en login/registro (AuthBrandingPanel.vue,
    // pedido explícito del usuario) — columna independiente de
    // hero_background_path, mismo controlador y mismo helper interno
    // (handleImageField), así que se prueba en paralelo al de arriba.
    public function test_an_admin_can_upload_an_auth_background(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.site.update'), [
            'auth_background' => UploadedFile::fake()->image('login-fondo.jpg'),
        ])->assertRedirect();

        $setting = SiteSetting::current();
        $this->assertNotNull($setting->auth_background_path);
        Storage::disk('public')->assertExists($setting->auth_background_path);
    }

    public function test_an_admin_can_remove_the_auth_background(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->post(route('admin.site.update'), [
            'auth_background' => UploadedFile::fake()->image('login-fondo.jpg'),
        ]);
        $path = SiteSetting::current()->auth_background_path;

        $this->actingAs($admin)->post(route('admin.site.update'), [
            'remove_auth_background' => true,
        ])->assertRedirect();

        $this->assertNull(SiteSetting::current()->auth_background_path);
        Storage::disk('public')->assertMissing($path);
    }

    // Uploading uno de los dos campos no debe tocar el otro — el bug que
    // este test evita: un helper mal escrito que borrara/reseteara ambos
    // campos por cada request en vez de solo el que trae archivo.
    public function test_uploading_one_background_does_not_touch_the_other(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->post(route('admin.site.update'), [
            'hero_background' => UploadedFile::fake()->image('hero.jpg'),
        ]);
        $heroPath = SiteSetting::current()->hero_background_path;

        $this->actingAs($admin)->post(route('admin.site.update'), [
            'auth_background' => UploadedFile::fake()->image('login-fondo.jpg'),
        ]);

        $setting = SiteSetting::current();
        $this->assertSame($heroPath, $setting->hero_background_path);
        $this->assertNotNull($setting->auth_background_path);
        Storage::disk('public')->assertExists($heroPath);
    }

    public function test_the_login_page_exposes_the_configured_auth_background_url(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->post(route('admin.site.update'), [
            'auth_background' => UploadedFile::fake()->image('login-fondo.jpg'),
        ]);
        $path = SiteSetting::current()->auth_background_path;

        // El login solo es visible sin sesión (RedirectIfAuthenticated) —
        // hay que cerrarla, si no la respuesta ni siquiera es la del login.
        $this->post(route('logout'));

        $response = $this->get(route('login'));

        $response->assertInertia(fn ($page) => $page
            ->where('authBackgroundUrl', Storage::disk('public')->url($path))
        );
    }
}
