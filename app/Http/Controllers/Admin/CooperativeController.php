<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Cooperative;
use App\Models\CooperativeDocument;
use App\Services\AdminAuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CooperativeController extends Controller
{
    private const REQUIRED_DOCUMENTS = ['ruc', 'legal_appointment', 'operating_authorization', 'operating_permit'];

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
        $documentTypes = $cooperative->documents()->pluck('type');
        $missing = collect(self::REQUIRED_DOCUMENTS)->reject(fn ($type) => $documentTypes->contains($type));
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages(['cooperative' => 'No se puede aprobar: faltan documentos obligatorios.']);
        }

        if ($cooperative->documents()->where('status', 'rejected')->exists()) {
            throw ValidationException::withMessages(['cooperative' => 'No se puede aprobar mientras exista documentación rechazada.']);
        }

        // Pedido explícito del usuario: seguro que proteja al
        // representante/dueño, a los conductores y a los vehículos —
        // autodeclarado, sin documento adjunto, pero igual bloquea la
        // aprobación si no está marcado (mismo criterio que los documentos).
        if (! $cooperative->has_insurance) {
            throw ValidationException::withMessages(['cooperative' => 'No se puede aprobar: falta declarar que cuenta con un seguro que proteja al representante, a los conductores y a los vehículos.']);
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
