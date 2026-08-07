<?php

namespace Tests\Feature\Admin;

use App\Models\AdBanner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Módulo de publicidad y promociones (pedido explícito del usuario):
 * banners tipo slider administrables por completo desde el panel admin.
 */
class AdBannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_regular_user_cannot_manage_banners(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.ad-banners.index'))->assertForbidden();
    }

    public function test_an_admin_can_create_a_banner_with_an_image(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.ad-banners.store'), [
            'title' => 'Lavadora Brillo',
            'description' => '20% de descuento para conductores',
            'button_label' => 'Ver oferta',
            'button_url' => 'https://lavadora-brillo.example.com',
            'image' => UploadedFile::fake()->image('banner.jpg'),
        ])->assertRedirect();

        $banner = AdBanner::firstOrFail();
        $this->assertSame('Lavadora Brillo', $banner->title);
        Storage::disk('public')->assertExists($banner->image_path);
    }

    public function test_creating_a_banner_without_an_image_fails(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.ad-banners.store'), [
            'title' => 'Sin imagen',
        ])->assertSessionHasErrors('image');
    }

    public function test_an_admin_can_update_a_banner_without_replacing_the_image(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['is_admin' => true]);
        $banner = AdBanner::query()->create([
            'image_path' => 'ad-banners/original.jpg',
            'title' => 'Original',
            'is_active' => true,
        ]);

        $this->actingAs($admin)->post(route('admin.ad-banners.update', $banner), [
            'title' => 'Actualizado',
            'is_active' => false,
        ])->assertRedirect();

        $banner->refresh();
        $this->assertSame('Actualizado', $banner->title);
        $this->assertFalse($banner->is_active);
        $this->assertSame('ad-banners/original.jpg', $banner->image_path);
    }

    public function test_an_admin_can_delete_a_banner(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['is_admin' => true]);
        $banner = AdBanner::query()->create([
            'image_path' => 'ad-banners/original.jpg',
            'title' => 'Para borrar',
            'is_active' => true,
        ]);

        $this->actingAs($admin)->delete(route('admin.ad-banners.destroy', $banner))->assertRedirect();

        $this->assertDatabaseMissing('ad_banners', ['id' => $banner->id]);
    }

    public function test_the_dashboard_only_shows_active_banners_within_their_date_range(): void
    {
        $user = User::factory()->create();

        AdBanner::query()->create(['image_path' => 'a.jpg', 'title' => 'Activo sin fechas', 'is_active' => true]);
        AdBanner::query()->create(['image_path' => 'b.jpg', 'title' => 'Inactivo', 'is_active' => false]);
        AdBanner::query()->create(['image_path' => 'c.jpg', 'title' => 'Vencido', 'is_active' => true, 'ends_at' => now()->subDay()]);
        AdBanner::query()->create(['image_path' => 'd.jpg', 'title' => 'Todavía no empieza', 'is_active' => true, 'starts_at' => now()->addDay()]);
        AdBanner::query()->create(['image_path' => 'e.jpg', 'title' => 'Vigente hoy', 'is_active' => true, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->has('adBanners', 2)
            ->where('adBanners.0.title', 'Activo sin fechas')
            ->where('adBanners.1.title', 'Vigente hoy')
        );
    }
}
