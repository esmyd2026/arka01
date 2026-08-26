<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\Cooperative;
use App\Models\CooperativeDocument;
use App\Services\PlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class CooperativeProfileController extends Controller
{
    private const REQUIRED_DOCUMENTS = [
        'ruc' => 'RUC',
        'legal_appointment' => 'Nombramiento del representante legal',
        'operating_authorization' => 'Documento habilitante',
        'operating_permit' => 'Permiso de funcionamiento',
    ];

    public function __construct(private readonly PlanLimits $planLimits) {}

    public function edit(Request $request): Response
    {
        $cooperative = $request->user()->cooperative()->with(['city', 'documents'])->firstOrFail();

        return Inertia::render('Cooperative/Profile', [
            'cooperative' => $cooperative,
            'cities' => City::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'requiredDocuments' => self::REQUIRED_DOCUMENTS,
            'planLimits' => $this->planLimits->forCooperative($request->user()),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $cooperative = $request->user()->cooperative()->with('documents')->firstOrFail();

        if ($cooperative->status === 'in_review') {
            throw ValidationException::withMessages([
                'cooperative' => 'La documentación está en revisión. Espere la respuesta del administrador antes de reemplazarla.',
            ]);
        }

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:150'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'ruc' => ['nullable', 'digits:13', Rule::unique('cooperatives', 'ruc')->ignore($cooperative->id)],
            'main_address' => ['nullable', 'string', 'max:255'],
            'stand_lat' => ['nullable', 'required_with:stand_lng', 'numeric', 'between:-90,90'],
            'stand_lng' => ['nullable', 'required_with:stand_lat', 'numeric', 'between:-180,180'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'province' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'legal_representative' => ['nullable', 'string', 'max:150'],
            'declared_driver_count' => ['required', 'integer', 'min:0', 'max:100000'],
            'declared_unit_count' => ['required', 'integer', 'min:0', 'max:100000'],
            // Pedido explícito del usuario: seguro que proteja al
            // representante/dueño, a los conductores afiliados y a los
            // vehículos — autodeclarado con un checkbox, sin documento
            // adjunto. Se puede guardar en false en un borrador; recién se
            // exige marcado al enviar a validación (ver submitForReview()).
            'has_insurance' => ['sometimes', 'boolean'],
            // Pedido explícito del usuario ("mejoremos la privacidad de las
            // cooperativas"): controla si su perfil público muestra la lista
            // real de conductores o solo la cantidad, con los conductores
            // "bloqueados" (ver CooperativeDirectoryController::show() y
            // Cooperative/Show.vue). No afecta al dueño ni a un admin, que
            // siguen viendo la flota completa siempre.
            'show_fleet_publicly' => ['sometimes', 'boolean'],
            'geographic_coverage' => ['nullable', 'string', 'max:2000'],
            'operating_hours' => ['nullable', 'string', 'max:1000'],
            'response_timeout_seconds' => ['required', 'integer', Rule::in([15, 30, 60])],
            'automatic_assignment_enabled' => ['required', 'boolean'],
            'manual_assignment_timeout_seconds' => ['required', 'integer', Rule::in([30])],
            'logo' => ['nullable', 'image', 'max:4096'],
            'ruc_document' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
            'legal_appointment_document' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
            'operating_authorization_document' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
            'operating_permit_document' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
            'other_documents' => ['nullable', 'array', 'max:5'],
            'other_documents.*' => ['file', 'mimes:pdf', 'max:10240'],
        ]);

        $fieldByType = [
            'ruc' => 'ruc_document',
            'legal_appointment' => 'legal_appointment_document',
            'operating_authorization' => 'operating_authorization_document',
            'operating_permit' => 'operating_permit_document',
        ];

        DB::transaction(function () use ($request, $cooperative, $validated, $fieldByType) {
            $data = collect($validated)->except([
                'logo',
                ...array_values($fieldByType),
                'other_documents',
            ])->all();

            if ($request->hasFile('logo')) {
                if ($cooperative->logo_path) {
                    Storage::disk('public')->delete($cooperative->logo_path);
                }
                $data['logo_path'] = $request->file('logo')->store('cooperatives/logos', 'public');
            }

            $cooperative->forceFill($data)->save();

            foreach ($fieldByType as $type => $field) {
                if ($request->hasFile($field)) {
                    $this->replaceDocument($cooperative, $type, self::REQUIRED_DOCUMENTS[$type], $request->file($field));
                }
            }

            foreach ($request->file('other_documents', []) as $file) {
                $this->storeDocument($cooperative, 'other', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), $file);
            }
        });

        return back()->with('status', 'Datos guardados correctamente. Puede continuar completándolos después.');
    }

    /** Valida el perfil ya guardado y recién entonces lo envía a administración. */
    public function submitForReview(Request $request): RedirectResponse
    {
        $cooperative = $request->user()->cooperative()->with('documents')->firstOrFail();
        abort_if($cooperative->status === 'in_review', 422, 'La documentación ya está en revisión.');

        Validator::make($cooperative->toArray(), [
            'name' => ['required', 'string', 'max:150'], 'legal_name' => ['required', 'string', 'max:200'],
            'ruc' => ['required', 'digits:13'], 'main_address' => ['required', 'string'],
            'stand_lat' => ['required', 'numeric'], 'stand_lng' => ['required', 'numeric'],
            'city_id' => ['required'], 'province' => ['required'], 'phone' => ['required'],
            'email' => ['required', 'email'], 'legal_representative' => ['required'],
            'geographic_coverage' => ['required'], 'operating_hours' => ['required'],
            // Pedido explícito del usuario: recién al enviar a validación se
            // exige tenerlo marcado — mismo criterio que los documentos
            // obligatorios de más abajo.
            'has_insurance' => ['accepted'],
        ], [
            'has_insurance.accepted' => 'Falta declarar que cuenta con un seguro que proteja al representante, a los conductores y a los vehículos.',
        ])->validate();

        foreach (self::REQUIRED_DOCUMENTS as $type => $label) {
            if (! $cooperative->documents->contains('type', $type)) {
                throw ValidationException::withMessages(["{$type}_document" => "Falta cargar: {$label}."]);
            }
        }

        $cooperative->forceFill([
            'status' => 'pending', 'submitted_at' => now(), 'reviewed_at' => null,
            'reviewed_by' => null, 'suspended_at' => null, 'rejection_reason' => null,
        ])->save();

        return back()->with('status', 'Información enviada. La cooperativa quedó pendiente de validación.');
    }

    /** Guarda el logo sin obligar a reenviar documentos ni datos legales. */
    public function updateLogo(Request $request): RedirectResponse
    {
        $cooperative = $request->user()->cooperative()->firstOrFail();
        $validated = $request->validate(['logo' => ['required', 'image', 'max:4096']]);
        $newPath = $validated['logo']->store('cooperatives/logos', 'public');

        if (! $newPath) {
            throw ValidationException::withMessages(['logo' => 'No se pudo guardar el logo. Inténtelo nuevamente.']);
        }

        $previousPath = $cooperative->logo_path;
        $cooperative->forceFill(['logo_path' => $newPath])->save();

        if ($previousPath && $previousPath !== $newPath) {
            Storage::disk('public')->delete($previousPath);
        }

        return back()->with('status', 'Logo actualizado correctamente.');
    }

    public function document(Request $request, CooperativeDocument $document): SymfonyResponse
    {
        $ownsCooperative = $request->user()?->cooperative?->id === $document->cooperative_id;
        abort_unless($ownsCooperative || $request->user()?->isAdmin(), 403);

        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->download($document->path, $document->original_name);
    }

    private function replaceDocument(Cooperative $cooperative, string $type, string $label, $file): void
    {
        $cooperative->documents()->where('type', $type)->get()->each(function (CooperativeDocument $document) {
            Storage::disk('local')->delete($document->path);
            $document->delete();
        });

        $this->storeDocument($cooperative, $type, $label, $file);
    }

    private function storeDocument(Cooperative $cooperative, string $type, string $label, $file): void
    {
        $path = $file->store('cooperative-documents/'.$cooperative->id, 'local');

        $cooperative->documents()->create([
            'type' => $type,
            'label' => $label,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'status' => 'pending',
        ]);
    }
}
