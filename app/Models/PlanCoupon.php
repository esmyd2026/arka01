<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cupón de descuento para suscripciones (pedido explícito del usuario:
 * "generar cupones de descuentos... para clientes y para conductores como
 * para cooperativa... si el cupon cubre el 100 o 50") — ver la migración
 * `plan_coupons` para el porqué del nombre (no `Coupon` a secas: esa clase
 * ya existe para el Centro de cupones y beneficios, un concepto distinto).
 * Se aplica como % de descuento sobre el precio de lista, a cualquier plan
 * del mismo `owner_type` — no está ligado a un plan puntual.
 */
class PlanCoupon extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'owner_type',
        'discount_percent',
        'max_redemptions',
        'expires_at',
        'is_active',
        'label',
        'created_by_admin_id',
        'referrer_user_id',
    ];

    protected $casts = [
        'discount_percent' => 'integer',
        'max_redemptions' => 'integer',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_admin_id');
    }

    /**
     * Pedido explícito del usuario: "colocarle un usuario para que cuando
     * se registren tenga a ese usuario que le dio el cupon como referido"
     * — quien canjea este cupón queda atribuido a este usuario (ver
     * SubscriptionRequestController::store()). Null en un cupón de
     * campaña general, sin nadie detrás.
     */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    /**
     * Cuántas veces ya se usó de verdad — nunca se guarda en un contador
     * propio (se recalcula siempre desde subscription_requests, mismo
     * criterio que PlanLimits::cooperativeDriverDiscountPercent()): un
     * pedido 'rejected' no cuenta como uso real, el usuario puede volver a
     * intentar con el mismo cupón.
     */
    public function redemptionsCount(): int
    {
        return SubscriptionRequest::query()
            ->where('plan_coupon_id', $this->id)
            ->whereIn('status', ['awaiting_proof', 'pending_review', 'approved'])
            ->count();
    }

    /**
     * Devuelve por qué NO se puede usar este cupón para este plan/usuario, o
     * null si sí se puede — mismo criterio de "mensaje o null" que
     * App\Services\SubscriptionPlanEligibility::reasonNotEligible().
     */
    public function reasonNotUsableFor(User $user, SubscriptionPlan $plan): ?string
    {
        if (! $this->is_active) {
            return 'Ese cupón ya no está activo.';
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return 'Ese cupón venció.';
        }

        if ($this->owner_type !== $plan->owner_type) {
            return 'Ese cupón no aplica a este tipo de plan.';
        }

        if ($this->max_redemptions !== null && $this->redemptionsCount() >= $this->max_redemptions) {
            return 'Ese cupón ya alcanzó su límite de usos.';
        }

        // Pedido explícito del usuario (mismo criterio que PlanPromotion::isEligibleFor()
        // — "que solo le aparezca a los que no les he dado nomas"): un
        // usuario no puede reusar el mismo cupón más de una vez.
        $alreadyUsed = SubscriptionRequest::query()
            ->where('user_id', $user->id)
            ->where('plan_coupon_id', $this->id)
            ->whereIn('status', ['awaiting_proof', 'pending_review', 'approved'])
            ->exists();

        if ($alreadyUsed) {
            return 'Ya usó ese cupón antes.';
        }

        return null;
    }
}
