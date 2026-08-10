<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\SubscriptionChange;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Notifications\PlanActivatedPushNotification;
use Illuminate\Support\Facades\DB;

/**
 * Activa (o reemplaza) el plan vigente de un usuario — única fuente de esta
 * lógica, usada tanto por la activación manual del admin como por el
 * pedido de un usuario cuando elige un plan de $0 (ver
 * SubscriptionRequestController::store(), no tiene sentido pedirle
 * comprobante de pago a algo que no cuesta nada).
 */
class SubscriptionActivator
{
    public function activate(User $user, SubscriptionPlan $plan, ?int $activatedBy, ?string $expiresAt = null, ?string $note = null): Subscription
    {
        $subscription = DB::transaction(function () use ($user, $plan, $activatedBy, $expiresAt, $note) {
            $previousSubscription = $user->activeSubscription($plan->owner_type);

            if ($previousSubscription) {
                $previousSubscription->update([
                    'status' => 'cancelled',
                    'note' => trim(($previousSubscription->note ? $previousSubscription->note.' — ' : '').'Reemplazada.'),
                ]);
            }

            $subscription = Subscription::query()->create([
                'user_id' => $user->id,
                'subscription_plan_id' => $plan->id,
                'status' => 'active',
                'started_at' => now(),
                'expires_at' => $expiresAt,
                'activated_by' => $activatedBy,
                'note' => $note,
            ]);

            SubscriptionChange::query()->create([
                'user_id' => $user->id,
                'old_subscription_plan_id' => $previousSubscription?->subscription_plan_id,
                'new_subscription_plan_id' => $plan->id,
                'changed_by' => $activatedBy,
                'note' => $note,
            ]);

            return $subscription;
        });

        // Fuera de la transacción a propósito (reporte del usuario: "activé
        // un plan y no le llegó la notificación" — no existía este aviso
        // todavía): un aviso push que fallara no tiene por qué revertir la
        // activación real del plan.
        $user->notify(new PlanActivatedPushNotification($subscription));

        return $subscription;
    }
}
