<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Promoción de precio por tiempo limitado en un plan (pedido explícito del
 * usuario: "regalar o promocionar los planes por un tiempo determinado...
 * pagá tanto y ahorrá tanto... después de tal fecha pagarías el valor real").
 * No es porcentaje, es un precio fijo promocional (confirmado con el
 * usuario) — el ahorro se calcula solo contra el precio de lista del plan.
 */
class PlanPromotion extends Model
{
    use HasFactory;

    protected $fillable = [
        'subscription_plan_id',
        'label',
        'promo_price',
        'starts_at',
        'ends_at',
        'is_active',
    ];

    protected $casts = [
        'promo_price' => 'decimal:2',
        'starts_at' => 'date',
        'ends_at' => 'date',
        'is_active' => 'boolean',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    /**
     * Activa Y dentro de su ventana de vigencia — mismo criterio que
     * App\Models\AdBanner::scopeVisible().
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhereDate('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhereDate('ends_at', '>=', now()));
    }

    public function savings(): float
    {
        return round((float) $this->plan->monthly_price - (float) $this->promo_price, 2);
    }

    /**
     * Pedido explícito del usuario ("que solo le aparezca a los que no les
     * he dado nomas"): se oculta sola a quien ya la usó una vez — un pedido
     * `rejected` no cuenta, puede volver a intentar (ej. comprobante poco
     * claro la primera vez).
     */
    public function isEligibleFor(User $user): bool
    {
        return ! SubscriptionRequest::query()
            ->where('user_id', $user->id)
            ->where('plan_promotion_id', $this->id)
            ->whereIn('status', ['awaiting_proof', 'pending_review', 'approved'])
            ->exists();
    }
}
