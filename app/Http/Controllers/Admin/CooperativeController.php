<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Cooperative;
use App\Models\CooperativeDocument;
use App\Services\AdminAuditLogger;
use App\Services\PlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CooperativeController extends Controller
{
    public function __construct(private readonly PlanLimits $planLimits) {}

    public function index(Request $request): Response
    {
        $cooperatives = Cooperative::query()
            ->with(['user', 'city'])
            ->withCount(['activeDriverMemberships', 'documents', 'clientLinks'])
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = trim($request->string('q')->toString());
                $query->where(fn ($query) => $query->where('name', 'like', "%{$term}%")
                    ->orWhere('legal_name', 'like', "%{$term}%")
                    ->orWhere('ruc', 'like', "%{$term}%"));
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->latest('submitted_at')
            ->paginate(20)
            ->withQueryString();

        // Pedido explícito del usuario: la lista del admin no mostraba nada
        // de visibilidad ni de cuántos clientes tiene cada cooperativa —
        // client_links_count ya se contaba (withCount de arriba) pero no se
        // usaba, y el plan/vigencia no se calculaba en absoluto acá.
        $cooperatives->getCollection()->transform(function (Cooperative $cooperative) {
            $planInfo = $cooperative->user ? $this->planLimits->forCooperative($cooperative->user) : null;
            $cooperative->plan_name = $planInfo['plan_name'] ?? null;
            $cooperative->subscription_status = $planInfo['subscription_status'] ?? null;

            return $cooperative;
        });

        return Inertia::render('Admin/Cooperatives/Index', [
            'cooperatives' => $cooperatives,
            'filters' => $request->only(['q', 'status']),
        ]);
    }

    public function show(Cooperative $cooperative): Response
    {
        $cooperative->load([
            'user',
            'city',
            'documents.reviewer',
            'driverMemberships.driver.driverProfile',
            'reviewer',
        ]);

        $auditLogs = AdminAuditLog::query()
            ->where('module', 'cooperatives')
            ->with('admin:id,name')
            ->latest('created_at')
            ->limit(200)
            ->get()
            ->filter(fn (AdminAuditLog $log) => (int) ($log->new_value['cooperative_id'] ?? $log->old_value['cooperative_id'] ?? 0) === $cooperative->id)
            ->values();

        return Inertia::render('Admin/Cooperatives/Show', ['cooperative' => $cooperative, 'auditLogs' => $auditLogs]);
    }

    public function markInReview(Request $request, Cooperative $cooperative): RedirectResponse
    {
        abort_unless($cooperative->status === 'pending' && $cooperative->submitted_at !== null, 422);
        $this->transition($request, $cooperative, 'in_review');

        return back()->with('status', 'Cooperativa marcada en revisión.');
    }

    public function approve(Request $request, Cooperative $cooperative): RedirectResponse
    {
        // Pedido explícito del usuario: bajar la fricción para arrancar —
        // ya no se exige tener los 4 documentos legales ni el seguro
        // declarado para aprobar (ver CooperativeProfileController::
        // submitForReview(), que tampoco los exige para enviar a revisión).
        // Sigue sin poder aprobarse mientras exista un documento que un
        // admin ya rechazó explícitamente: eso sí es un problema activo,
        // documento haya sido obligatorio o no.
        if ($cooperative->documents()->where('status', 'rejected')->exists()) {
            throw ValidationException::withMessages(['cooperative' => 'No se puede aprobar mientras exista documentación rechazada.']);
        }

        $cooperative->documents()->where('status', 'pending')->update([
            'status' => 'approved',
            'reviewed_at' => now(),
            'reviewed_by' => $request->user()->id,
        ]);
        $this->transition($request, $cooperative, 'approved');

        return back()->with('status', 'Cooperativa aprobada y visible en el directorio.');
    }

    public function reject(Request $request, Cooperative $cooperative): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $this->transition($request, $cooperative, 'rejected', $validated['reason']);

        return back()->with('status', 'Cooperativa rechazada con observación.');
    }

    public function suspend(Request $request, Cooperative $cooperative): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $this->transition($request, $cooperative, 'suspended', $validated['reason']);

        return back()->with('status', 'Cooperativa suspendida.');
    }

    public function reactivate(Request $request, Cooperative $cooperative): RedirectResponse
    {
        $this->transition($request, $cooperative, 'approved');

        return back()->with('status', 'Cooperativa reactivada.');
    }

    public function updateWhatsApp(Request $request, Cooperative $cooperative): RedirectResponse
    {
        $validated = $request->validate(['enabled' => ['required', 'boolean']]);
        $cooperative->forceFill(['whatsapp_ride_actions_enabled' => $validated['enabled']])->save();

        return back()->with('status', $validated['enabled']
            ? 'Operación por WhatsApp habilitada para la cooperativa.'
            : 'Operación por WhatsApp deshabilitada para todos sus conductores.');
    }

    /**
     * Pedido explícito del usuario: "yo le colocaría ese check para que
     * puedan aparecer a cualquier cliente sin necesidad de que lo agregue a
     * su flota de cooperativa" — visible en "Elige tu conductor" con la
     * insignia "Pública", sin pasar por ClientCooperative (ver
     * RideRequestController::create() y RideRequestCreator::create()).
     */
    public function updatePublicVisibility(Request $request, Cooperative $cooperative): RedirectResponse
    {
        $validated = $request->validate(['is_public' => ['required', 'boolean']]);
        $cooperative->forceFill(['is_public' => $validated['is_public']])->save();

        return back()->with('status', $validated['is_public']
            ? 'Cooperativa marcada como pública: aparece en "Elige tu conductor" de cualquier cliente.'
            : 'Cooperativa ya no es pública: solo la ven los clientes que la agregaron.');
    }

    public function reviewDocument(Request $request, CooperativeDocument $document): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'reason' => ['nullable', 'required_if:status,rejected', 'string', 'max:1000'],
        ]);

        $old = $document->only(['status', 'rejection_reason']);
        $document->forceFill([
            'status' => $validated['status'],
            'rejection_reason' => $validated['status'] === 'rejected' ? $validated['reason'] : null,
            'reviewed_at' => now(),
            'reviewed_by' => $request->user()->id,
        ])->save();

        if ($validated['status'] === 'rejected') {
            $document->cooperative->forceFill([
                'status' => 'rejected',
                'rejection_reason' => "Documento {$document->label}: {$validated['reason']}",
                'reviewed_at' => now(),
                'reviewed_by' => $request->user()->id,
            ])->save();
        }

        AdminAuditLogger::log(
            $request->user()->id,
            'cooperative.document.review',
            'cooperatives',
            ['cooperative_id' => $document->cooperative_id, 'document_id' => $document->id, ...$old],
            ['cooperative_id' => $document->cooperative_id, 'document_id' => $document->id, ...$document->only(['status', 'rejection_reason'])],
        );

        return back()->with('status', 'Documento revisado.');
    }

    private function transition(Request $request, Cooperative $cooperative, string $status, ?string $reason = null): void
    {
        $old = $cooperative->only(['status', 'rejection_reason', 'suspended_at']);

        $cooperative->forceFill([
            'status' => $status,
            'rejection_reason' => $reason,
            'reviewed_at' => now(),
            'reviewed_by' => $request->user()->id,
            'suspended_at' => $status === 'suspended' ? now() : null,
        ])->save();

        AdminAuditLogger::log(
            $request->user()->id,
            'cooperative.status.'.$status,
            'cooperatives',
            ['cooperative_id' => $cooperative->id, ...$old],
            ['cooperative_id' => $cooperative->id, ...$cooperative->only(['status', 'rejection_reason', 'suspended_at'])],
        );
    }
}
