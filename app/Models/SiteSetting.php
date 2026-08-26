<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Fila única con configuración general del sitio público (pedido explícito
 * del usuario: la imagen de fondo del hero de Welcome.vue, subida desde
 * /admin/sitio en vez de depender de copiarla a mano a `public/img/`) —
 * mismo patrón singleton que PricingSetting/WhatsAppSetting/ChatbotSetting.
 */
class SiteSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'hero_background_path',
        'auth_background_path',
        'updated_by',
        'facebook_url',
        'instagram_url',
        'tiktok_url',
    ];

    protected $appends = ['hero_background_url', 'auth_background_url'];

    public static function current(): self
    {
        return self::query()->firstOrFail();
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // Mismo criterio que User::getAvatarUrlAttribute() — el disco 'public'
    // ya está enlazado (`artisan storage:link`), acá solo se arma la URL.
    public function getHeroBackgroundUrlAttribute(): ?string
    {
        return $this->hero_background_path
            ? Storage::disk('public')->url($this->hero_background_path)
            : null;
    }

    // Fondo del panel de marca en login/registro (AuthBrandingPanel.vue) —
    // pedido explícito del usuario, columna independiente de la del hero.
    public function getAuthBackgroundUrlAttribute(): ?string
    {
        return $this->auth_background_path
            ? Storage::disk('public')->url($this->auth_background_path)
            : null;
    }
}
