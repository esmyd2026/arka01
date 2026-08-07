<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Espacio publicitario del módulo de monetización (pedido explícito del
 * usuario): banners tipo slider, administrados por completo desde el panel
 * admin — imagen, título, descripción, botón de acción y vigencia por fecha.
 */
class AdBanner extends Model
{
    use HasFactory;

    protected $appends = ['image_url'];

    protected $fillable = [
        'image_path',
        'title',
        'description',
        'button_label',
        'button_url',
        'is_active',
        'sort_order',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'starts_at' => 'date',
        'ends_at' => 'date',
    ];

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    /**
     * Activo Y dentro de su ventana de vigencia (sección de monetización): un
     * banner pausado o fuera de fecha no debe aparecer, aunque siga existiendo
     * para que el admin lo reactive más adelante sin tener que recrearlo.
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhereDate('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhereDate('ends_at', '>=', now()));
    }
}
