<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Centro de cupones y beneficios (pedido explícito del usuario): promoción de
 * un comercio aliado, exclusiva de un lado (cliente o conductor) — cada lado
 * tiene su propio listado, completamente independiente del otro.
 */
class Coupon extends Model
{
    use HasFactory;

    protected $appends = ['image_url'];

    protected $fillable = [
        'audience',
        'image_path',
        'title',
        'description',
        'button_label',
        'button_url',
        'is_active',
        'sort_order',
        'expires_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'expires_at' => 'date',
    ];

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    /**
     * Activo y sin vencer todavía (en blanco = sin vencimiento).
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhereDate('expires_at', '>=', now()));
    }
}
