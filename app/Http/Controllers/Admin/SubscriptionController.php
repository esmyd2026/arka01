<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\SubscriptionChange;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionRequest;
use App\Models\User;
use App\Notifications\SubscriptionRequestRejectedPushNotification;
use App\Services\PlanLimits;
use App\Services\SubscriptionActivator;
use App\Services\SubscriptionPlanEligibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Activación manual de suscripciones (sección 7.5: no hay pasarela de pago,
 * un admin confirma la transferencia bancaria y activa el plan a mano) con
 * auditoría completa de cada cambio (sección 9.6).
 */
class SubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionPlanEligibility $eligibility,
        private readonly SubscriptionActivator $activator,
        private readonly PlanLimits $planLimits,
    ) {}

    /**
     * Buscador de usuarios + su plan vigente (driver y cliente) para activar
     * o cambiar, más el historial de cambios reciente como bitácora.
     *
     * Pedido explícito del usuario: la lista se veía como una pila de
     * tarjetas que ocupaba mucho espacio y no tenía forma de filtrar ni
     * ordenar — se suma filtro por rol, por si tiene plan pago o está en el
     * gratis, y orden por nombre o por vencimiento (próximo a vencer primero).
     */
    public function index(Request $request): Response
    {
        $search = $request->string('q')->trim()->toString();
        $roleFilter = $request->string('role')->toString();
        $planStatusFilter = $request->string('plan_status')->toString();
        // Pedido explícito del usuario: fecha de registro/actualización
        // visibles y como orden por defecto (los suscriptores más nuevos
        // primero) — antes ordenaba por nombre, sin ninguna forma de ver
        // quién se sumó último.
        $sort = in_array($request->string('sort')->toString(), ['name', 'expiry', 'created_at', 'updated_at'], true)
            ? $request->string('sort')->toString()
            : 'created_at';
        $direction = in_array($request->string('direction')->toString(), ['asc', 'desc'], true)
            ? $request->string('direction')->toString()
            : 'desc';

        $users = User::query()
            ->when($search !== '', function (Builder $query) use ($search) {
                $query->where(function (Builder $query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($roleFilter !== '', fn (Builder $query) => $query->where('role', $roleFilter))
            // El estado de plan (con plan pago / en el gratis) no aplica al
            // admin, que no tiene perfil de cliente ni de conductor — sin
            // este descarte, un admin caía siempre del lado "gratis" al no
            // tener ninguna suscripción, aunque el filtro no es para él.
            ->when($planStatusFilter === 'con_plan', fn (Builder $query) => $query->where('role', '!=', 'admin')->whereHas(
                'subscriptions',
                fn (Builder $query) => $query->whereIn('status', ['active', 'grace'])
            ))
            ->when($planStatusFilter === 'gratis', fn (Builder $query) => $query->where('role', '!=', 'admin')->whereDoesntHave(
                'subscriptions',
                fn (Builder $query) => $query->whereIn('status', ['active', 'grace'])
            ))
            ->with([
                'subscriptions' => fn ($query) => $query->whereIn('status', ['active', 'grace'])->with('plan'),
            ])
            // Vencimiento más próximo entre las suscripciones vigentes del
            // usuario (a lo sumo tiene una del lado conductor y una del lado
            // cliente, pero solo una le corresponde según su rol único) — se
            // trae como columna aparte solo para poder ordenar por ella.
            ->selectSub(
                Subscription::query()
                    ->selectRaw('expires_at')
                    ->whereColumn('subscriptions.user_id', 'users.id')
                    ->whereIn('status', ['active', 'grace'])
                    ->orderByRaw('expires_at is null, expires_at asc')
                    ->limit(1),
                'next_expiry'
            )
            ->addSelect('users.*')
            ->when($sort === 'expiry', fn (Builder $query) => $query->orderByRaw("next_expiry is null, next_expiry {$direction}"))
            ->when($sort === 'name', fn (Builder $query) => $query->orderBy('name', $direction))
            ->when($sort === 'created_at', fn (Builder $query) => $query->orderBy('users.created_at', $direction))
            ->when($sort === 'updated_at', fn (Builder $query) => $query->orderBy('users.updated_at', $direction))
            ->paginate(20)
            ->withQueryString();

        $recentChanges = SubscriptionChange::query()
            ->with(['user', 'oldPlan', 'newPlan', 'changedBy'])
            ->latest()
            ->limit(20)
            ->get();

        // Comprobantes de pago esperando revisión (consideración agregada al
        // alcance) — lo primero que un admin necesita ver al entrar acá.
        $pendingRequests = SubscriptionRequest::query()
            ->where('status', 'pending_review')
            // planPromotion (pedido explícito del usuario): si el pedido
            // viene de una promoción, el admin necesita saber qué monto
            // correspondía revisar en el comprobante, no el precio de lista.
            ->with(['user', 'plan', 'planPromotion'])
            ->latest()
            ->get()
            // Descuento por cooperativa (pedido explícito del usuario): sin
            // esto, el admin esperaría el precio de lista completo y
            // podría rechazar un comprobante legítimo por un monto menor.
            ->each(function (SubscriptionRequest $subscriptionRequest) {
                $subscriptionRequest->cooperative_discount = (! $subscriptionRequest->planPromotion && $subscriptionRequest->plan->owner_type === 'driver')
                    ? $this->planLimits->driverDiscountFor($subscriptionRequest->plan, $subscriptionRequest->user)
                    : null;
            });

        return Inertia::render('Admin/Subscriptions', [
            'users' => $users,
            // Solo planes activos para dar de alta: uno discontinuado no
            // debería ofrecerse para una activación nueva (sí se sigue viendo
            // en /admin/planes y en "Mi plan" de quien ya lo tiene).
            'plans' => SubscriptionPlan::query()->where('is_active', true)->orderBy('owner_type')->orderBy('sort_order')->get(),
            'recentChanges' => $recentChanges,
            'pendingRequests' => $pendingRequests,
            'search' => $search,
            'filters' => [
                'role' => $roleFilter,
                'plan_status' => $planStatusFilter,
                'sort' => $sort,
                'direction' => $direction,
            ],
        ]);
    }

    /**
     * Activa (o cambia) el plan de un usuario. Si ya tenía uno vigente del
     * mismo tipo (driver/cliente), lo cierra como "reemplazado" antes de
     * activar el nuevo — nunca conviven dos suscripciones vigentes del mismo
     * tipo para el mismo usuario.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'subscription_plan_id' => ['required', 'integer', 'exists:subscription_plans,id'],
            'expires_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $plan = SubscriptionPlan::query()->findOrFail($validated['subscription_plan_id']);
        $user = User::query()->findOrFail($validated['user_id']);

        // Pedido explícito del usuario: cada cuenta es cliente O conductor,
        // nunca las dos (sección 3.1) — no tiene sentido activarle un plan
        // del lado que no le corresponde (ej. un plan de conductor a alguien
        // que nunca activó su perfil de conductor). Antes solo se evitaba
        // ocultando la opción en el formulario, sin nada del lado del
        // servidor que lo impidiera de verdad.
        if ($plan->owner_type === 'driver' && ! $user->isDriver()) {
            throw ValidationException::withMessages([
                'subscription_plan_id' => 'Este usuario no tiene perfil de conductor activado — no se le puede dar un plan de ese lado.',
            ]);
        }

        if ($plan->owner_type === 'client' && ! $user->isClient()) {
            throw ValidationException::withMessages([
                'subscription_plan_id' => 'Este usuario no es cliente (es conductor o admin) — no se le puede dar un plan de ese lado.',
            ]);
        }

        // Mismo criterio que los dos de arriba, ahora que la cooperativa
        // también tiene sus propios planes reales (pedido explícito del
        // usuario) — antes no hacía falta, no existía forma de activarle
        // un plan de cooperativa a nadie desde acá con sentido.
        if ($plan->owner_type === 'cooperative' && ! $user->isCooperative()) {
            throw ValidationException::withMessages([
                'subscription_plan_id' => 'Este usuario no es una cooperativa — no se le puede dar un plan de ese lado.',
            ]);
        }

        $this->activator->activate($user, $plan, $request->user()->id, $validated['expires_at'] ?? null, $validated['note'] ?? null);

        return back()->with('status', 'Suscripción activada.');
    }

    /**
     * Aprueba un pedido de plan con comprobante ya subido (consideración
     * agregada al alcance): activa la suscripción real y cierra el pedido.
     */
    public function approveRequest(Request $request, SubscriptionRequest $subscriptionRequest): RedirectResponse
    {
        if ($subscriptionRequest->status !== 'pending_review') {
            throw ValidationException::withMessages([
                'subscription_request' => 'Este pedido no está esperando revisión.',
            ]);
        }

        // El usuario pudo haber sumado más clientes/flotas entre que pidió el
        // plan y que se revisó el comprobante — se revalida acá también
        // (consideración agregada al alcance). Si el admin de verdad necesita
        // forzarlo, sigue disponible la activación manual de siempre.
        if ($reason = $this->eligibility->reasonNotEligible($subscriptionRequest->user, $subscriptionRequest->plan)) {
            throw ValidationException::withMessages(['subscription_request' => $reason]);
        }

        $note = $subscriptionRequest->planPromotion
            ? "Aprobado desde comprobante de pago (promoción: {$subscriptionRequest->planPromotion->label})."
            : 'Aprobado desde comprobante de pago subido por el usuario.';

        $this->activator->activate(
            $subscriptionRequest->user,
            $subscriptionRequest->plan,
            $request->user()->id,
            null,
            $note
        );

        $subscriptionRequest->update([
            'status' => 'approved',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        Log::info('Pedido de plan aprobado.', [
            'subscription_request_id' => $subscriptionRequest->id,
            'admin_id' => $request->user()->id,
        ]);

        return back()->with('status', 'Pedido aprobado y suscripción activada.');
    }

    /**
     * Rechaza un pedido (ej. comprobante ilegible o pago no encontrado) — el
     * usuario puede volver a subir uno nuevo sobre el mismo pedido.
     */
    public function rejectRequest(Request $request, SubscriptionRequest $subscriptionRequest): RedirectResponse
    {
        if ($subscriptionRequest->status !== 'pending_review') {
            throw ValidationException::withMessages([
                'subscription_request' => 'Este pedido no está esperando revisión.',
            ]);
        }

        $validated = $request->validate([
            'admin_note' => ['nullable', 'string', 'max:255'],
        ]);

        $subscriptionRequest->update([
            'status' => 'rejected',
            'admin_note' => $validated['admin_note'] ?? null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        // La aprobación ya avisa mediante SubscriptionActivator. El rechazo
        // también debe llegar fuera de la pestaña para que el usuario pueda
        // corregir el comprobante sin descubrirlo días después.
        $subscriptionRequest->user->notify(new SubscriptionRequestRejectedPushNotification($subscriptionRequest));

        Log::info('Pedido de plan rechazado.', [
            'subscription_request_id' => $subscriptionRequest->id,
            'admin_id' => $request->user()->id,
        ]);

        return back()->with('status', 'Pedido rechazado.');
    }

    /**
     * El admin da de baja una suscripción antes de su vencimiento natural
     * (por ejemplo, transferencia rechazada o pedido del propio usuario). El
     * usuario vuelve a quedar en el plan Gratis correspondiente (sección 9.6).
     */
    public function expire(Request $request, Subscription $subscription): RedirectResponse
    {
        $subscription->update(['status' => 'expired']);

        SubscriptionChange::query()->create([
            'user_id' => $subscription->user_id,
            'old_subscription_plan_id' => $subscription->subscription_plan_id,
            'new_subscription_plan_id' => $this->freePlanIdFor($subscription->plan->owner_type),
            'changed_by' => $request->user()->id,
            'note' => 'Baja manual desde el panel admin.',
        ]);

        return back()->with('status', 'Suscripción dada de baja.');
    }

    private function freePlanIdFor(string $ownerType): int
    {
        return SubscriptionPlan::query()
            ->where('owner_type', $ownerType)
            ->where('code', 'gratis')
            ->value('id');
    }
}
