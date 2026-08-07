<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\SubscriptionChange;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionRequest;
use App\Models\User;
use App\Services\SubscriptionActivator;
use App\Services\SubscriptionPlanEligibility;
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
    ) {}

    /**
     * Buscador de usuarios + su plan vigente (driver y cliente) para activar
     * o cambiar, más el historial de cambios reciente como bitácora.
     */
    public function index(Request $request): Response
    {
        $search = $request->string('q')->trim()->toString();

        $users = User::query()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->with([
                'subscriptions' => fn ($query) => $query->whereIn('status', ['active', 'grace'])->with('plan'),
            ])
            ->orderBy('name')
            ->paginate(15)
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
            ->with(['user', 'plan'])
            ->latest()
            ->get();

        return Inertia::render('Admin/Subscriptions', [
            'users' => $users,
            // Solo planes activos para dar de alta: uno discontinuado no
            // debería ofrecerse para una activación nueva (sí se sigue viendo
            // en /admin/planes y en "Mi plan" de quien ya lo tiene).
            'plans' => SubscriptionPlan::query()->where('is_active', true)->orderBy('owner_type')->orderBy('sort_order')->get(),
            'recentChanges' => $recentChanges,
            'pendingRequests' => $pendingRequests,
            'search' => $search,
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

        $this->activator->activate(
            $subscriptionRequest->user,
            $subscriptionRequest->plan,
            $request->user()->id,
            null,
            'Aprobado desde comprobante de pago subido por el usuario.'
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
