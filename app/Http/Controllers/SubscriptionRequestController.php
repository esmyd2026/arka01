<?php

namespace App\Http\Controllers;

use App\Models\PlanCoupon;
use App\Models\PlanPromotion;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionRequest;
use App\Services\PlanLimits;
use App\Services\SubscriptionActivator;
use App\Services\SubscriptionPlanEligibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Elegir este plan" desde Mi plan (consideración agregada al alcance): no
 * hay pasarela de pago (sección 7.5), así que esto no cobra nada — junta el
 * pedido y el comprobante para que un admin lo revise y active la
 * suscripción real (ver Admin\SubscriptionController).
 */
class SubscriptionRequestController extends Controller
{
    public function __construct(
        private readonly SubscriptionPlanEligibility $eligibility,
        private readonly SubscriptionActivator $activator,
        private readonly PlanLimits $planLimits,
    ) {}

    /**
     * El usuario elige un plan. Si ya tiene un pedido esperando revisión
     * (todavía no aprobado ni rechazado) del mismo tipo, no se puede mandar
     * uno nuevo encima — primero hay que esperar la respuesta o que se
     * rechace el anterior. Tampoco se puede elegir un plan (ni siquiera
     * volver al Gratis) que ya no le alcanza con lo que tiene armado
     * (consideración agregada al alcance).
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'subscription_plan_id' => ['required', 'integer', 'exists:subscription_plans,id'],
            'plan_promotion_id' => ['nullable', 'integer', 'exists:plan_promotions,id'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
        ]);

        $plan = SubscriptionPlan::query()->findOrFail($validated['subscription_plan_id']);
        $user = $request->user();

        $hasPendingRequest = SubscriptionRequest::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['awaiting_proof', 'pending_review'])
            ->whereHas('plan', fn ($query) => $query->where('owner_type', $plan->owner_type))
            ->exists();

        if ($hasPendingRequest) {
            throw ValidationException::withMessages([
                'subscription_plan_id' => 'Ya tiene un pedido de plan esperando revisión. Espere la respuesta antes de elegir otro.',
            ]);
        }

        if ($reason = $this->eligibility->reasonNotEligible($user, $plan)) {
            throw ValidationException::withMessages(['subscription_plan_id' => $reason]);
        }

        // Promoción de precio por tiempo limitado (pedido explícito del
        // usuario) — nunca se confía en el precio que haya mostrado el
        // navegador, se revalida acá: tiene que existir, seguir vigente,
        // ser de ESTE plan, y el usuario todavía no haberla usado.
        $promotion = null;
        if (! empty($validated['plan_promotion_id'])) {
            $promotion = PlanPromotion::visible()
                ->where('subscription_plan_id', $plan->id)
                ->find($validated['plan_promotion_id']);

            if (! $promotion || ! $promotion->isEligibleFor($user)) {
                throw ValidationException::withMessages([
                    'plan_promotion_id' => 'Esa promoción ya no está disponible para su cuenta.',
                ]);
            }
        }

        // Cupón de descuento (pedido explícito del usuario: "generar
        // cupones de descuentos... para clientes y para conductores como
        // para cooperativa... si el cupon cubre el 100 o 50") — mismo
        // criterio que la promoción: nunca se confía en lo que mandó el
        // navegador, se busca y se revalida acá por completo. Si hay
        // cupón, gana por sobre la promoción y el descuento de cooperativa
        // (es la acción más específica y puntual del usuario en este
        // pedido) — no se combinan entre sí.
        $coupon = null;
        $couponCode = trim((string) ($validated['coupon_code'] ?? ''));
        if ($couponCode !== '') {
            $coupon = PlanCoupon::query()->whereRaw('UPPER(code) = ?', [mb_strtoupper($couponCode)])->first();

            if (! $coupon) {
                throw ValidationException::withMessages(['coupon_code' => 'Ese código de cupón no existe.']);
            }

            if ($reason = $coupon->reasonNotUsableFor($user, $plan)) {
                throw ValidationException::withMessages(['coupon_code' => $reason]);
            }

            // Pedido explícito del usuario: "colocarle un usuario para que
            // cuando se registren tenga a ese usuario que le dio el cupon
            // como referido" — se atribuye apenas usa el cupón (no espera a
            // que el pedido se apruebe: ya demostró venir de ese cupón).
            // Gana el PRIMER referidor de la cuenta (mismo criterio "se
            // queda quemado" que la búsqueda manual en el perfil) — si ya
            // tenía uno (por enlace o ya asignado antes), este cupón no lo
            // pisa.
            if ($coupon->referrer_user_id && ! $user->referred_by_user_id) {
                $user->forceFill(['referred_by_user_id' => $coupon->referrer_user_id])->save();
            }
        }

        // Descuento por cooperativa (pedido explícito del usuario) — nunca
        // se confía en lo que mandó el navegador, se recalcula acá igual
        // que la promoción. Solo aplica del lado conductor, y solo si no
        // hay cupón ni promoción de por medio (esos dos ganan).
        $cooperativeDiscountPercent = (! $coupon && ! $promotion && $plan->owner_type === 'driver')
            ? $this->planLimits->cooperativeDriverDiscountPercent($user)
            : 0;

        $effectivePrice = match (true) {
            $coupon !== null => round((float) $plan->monthly_price * (1 - $coupon->discount_percent / 100), 2),
            $promotion !== null => (float) $promotion->promo_price,
            default => (float) $plan->monthly_price * (1 - $cooperativeDiscountPercent / 100),
        };

        // Un precio en $0 no tiene nada que transferir — pedirle un
        // comprobante no tiene sentido, así que se activa directo sin pasar
        // por revisión (mismo criterio de siempre para un plan gratis de
        // catálogo, generalizado acá al precio EFECTIVO, de cupón, de promo
        // o de lista).
        if ($effectivePrice <= 0) {
            $note = match (true) {
                $coupon !== null => "Auto-activado por cupón: {$coupon->code} ({$coupon->discount_percent}% off).",
                $promotion !== null => "Auto-activado por promoción: {$promotion->label}.",
                default => 'Auto-activado: plan sin costo, no necesita comprobante.',
            };

            $this->activator->activate($user, $plan, $user->id, null, $note);

            // Solo si hubo cupón o promoción de por medio: sin este
            // registro, reasonNotUsableFor()/isEligibleFor() no tendrían
            // cómo enterarse de que este usuario ya lo usó, y se lo
            // seguirían ofreciendo para siempre — un plan gratis de
            // catálogo de toda la vida no necesita este rastro, no cambia
            // nada de su comportamiento.
            if ($coupon || $promotion) {
                SubscriptionRequest::query()->create([
                    'user_id' => $user->id,
                    'subscription_plan_id' => $plan->id,
                    'plan_promotion_id' => $promotion?->id,
                    'plan_coupon_id' => $coupon?->id,
                    'status' => 'approved',
                    'reviewed_by' => $user->id,
                    'reviewed_at' => now(),
                    'admin_note' => $coupon
                        ? "Auto-aprobado por cupón: {$coupon->code}."
                        : "Auto-aprobado por promoción: {$promotion->label}.",
                ]);
            }

            Log::info('Plan sin costo auto-activado sin comprobante.', [
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'plan_promotion_id' => $promotion?->id,
                'plan_coupon_id' => $coupon?->id,
            ]);

            return back()->with('status', 'Plan activado. Como no tiene costo, no hace falta comprobante de pago.');
        }

        $subscriptionRequest = SubscriptionRequest::query()->create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'plan_promotion_id' => $promotion?->id,
            'plan_coupon_id' => $coupon?->id,
            'status' => 'awaiting_proof',
        ]);

        Log::info('Plan elegido, esperando comprobante de pago.', [
            'subscription_request_id' => $subscriptionRequest->id,
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'plan_promotion_id' => $promotion?->id,
            'plan_coupon_id' => $coupon?->id,
        ]);

        return back()->with('status', 'Plan elegido. Ahora suba el comprobante de su transferencia para que lo revisemos.');
    }

    /**
     * Sube (o reemplaza, si lo habían rechazado por una foto poco clara) el
     * comprobante de pago — recién ahí el pedido pasa a estar esperando
     * revisión del admin.
     */
    public function uploadProof(Request $request, SubscriptionRequest $subscriptionRequest): RedirectResponse
    {
        if ($subscriptionRequest->user_id !== $request->user()->id) {
            abort(403);
        }

        if (! in_array($subscriptionRequest->status, ['awaiting_proof', 'rejected'], true)) {
            throw ValidationException::withMessages([
                'payment_proof' => 'Este pedido ya no admite un comprobante nuevo.',
            ]);
        }

        $validated = $request->validate([
            'payment_proof' => ['required', 'image', 'max:5120'],
        ]);

        // Auditoría de seguridad (pedido explícito del usuario): es un
        // comprobante bancario (cuenta, monto, titular) — disco privado, se
        // sirve solo al dueño del pedido o a un admin (ver paymentProof()).
        $path = $validated['payment_proof']->store('payment-proofs', 'local');

        $subscriptionRequest->update([
            'payment_proof_path' => $path,
            'status' => 'pending_review',
            'admin_note' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);

        Log::info('Comprobante de pago subido.', [
            'subscription_request_id' => $subscriptionRequest->id,
            'user_id' => $request->user()->id,
        ]);

        return back()->with('status', 'Comprobante recibido. Le avisamos apenas lo revisemos.');
    }

    /**
     * El usuario se arrepiente antes de que lo revisen — libera el cupo para
     * poder elegir otro plan.
     */
    public function cancel(Request $request, SubscriptionRequest $subscriptionRequest): RedirectResponse
    {
        if ($subscriptionRequest->user_id !== $request->user()->id) {
            abort(403);
        }

        if (! in_array($subscriptionRequest->status, ['awaiting_proof', 'pending_review'], true)) {
            throw ValidationException::withMessages([
                'subscription_request' => 'Este pedido ya fue resuelto.',
            ]);
        }

        $subscriptionRequest->delete();

        return back()->with('status', 'Pedido cancelado.');
    }

    /**
     * Sirve el comprobante de pago desde el disco privado (auditoría de
     * seguridad, pedido explícito del usuario): es un documento financiero,
     * solo lo puede ver quien lo subió o un admin.
     */
    public function paymentProof(Request $request, SubscriptionRequest $subscriptionRequest): Response
    {
        abort_unless($subscriptionRequest->user_id === $request->user()->id || $request->user()->isAdmin(), 403);
        abort_if(blank($subscriptionRequest->payment_proof_path), 404);

        return Storage::disk('local')->response($subscriptionRequest->payment_proof_path);
    }
}
