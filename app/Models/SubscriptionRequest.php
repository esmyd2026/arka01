<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pedido de activación de un plan hecho por el propio usuario (consideración
 * agregada al alcance), con el comprobante de pago adjunto — ver la
 * migración `subscription_requests` para el detalle de cada estado.
 */
class SubscriptionRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'subscription_plan_id',
        'plan_promotion_id',
        'payment_proof_path',
        'status',
        'admin_note',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    protected $appends = ['payment_proof_url'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function planPromotion(): BelongsTo
    {
        return $this->belongsTo(PlanPromotion::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function getPaymentProofUrlAttribute(): ?string
    {
        // Auditoría de seguridad: comprobante bancario, disco privado — pasa
        // por un controlador que chequea dueño/admin (ver
        // SubscriptionRequestController::paymentProof()), no un link directo.
        return $this->payment_proof_path ? route('subscription-requests.payment-proof', $this) : null;
    }
}
