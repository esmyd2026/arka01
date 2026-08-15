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
            'name' => ['required', 'string', 'max:150'],
            'legal_name' => ['required', 'string', 'max:200'],
            'ruc' => ['required', 'digits:13', Rule::unique('cooperatives', 'ruc')->ignore($cooperative->id)],
            'main_address' => ['required', 'string', 'max:255'],
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'province' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['required', 'email', 'max:255'],
            'legal_representative' => ['required', 'string', 'max:150'],
            'declared_driver_count' => ['required', 'integer', 'min:0', 'max:100000'],
            'declared_unit_count' => ['required', 'integer', 'min:0', 'max:100000'],
            'geographic_coverage' => ['required', 'string', 'max:2000'],
            'operating_hours' => ['required', 'string', 'max:1000'],
            'response_timeout_seconds' => ['required', 'integer', Rule::in([15, 30, 60])],
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

        foreach ($fieldByType as $type => $field) {
            if (! $request->hasFile($field) && ! $cooperative->documents->contains('type', $type)) {
                throw ValidationException::withMessages([$field => 'Este documento es obligatorio para enviar la cooperativa a validación.']);
            }
        }

        $documentChanged = collect($fieldByType)->contains(fn ($field) => $request->hasFile($field))
            || $request->hasFile('other_documents');

        DB::transaction(function () use ($request, $cooperative, $validated, $fieldByType, $documentChanged) {
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

            // Toda presentación inicial, corrección rechazada o cambio legal
            // posterior a una aprobación vuelve a la cola de validación.
            $data['status'] = 'pending';
            $data['submitted_at'] = now();
            $data['reviewed_at'] = null;
            $data['reviewed_by'] = null;
            $data['suspended_at'] = null;
            if ($documentChanged || $cooperative->status !== 'approved') {
                $data['rejection_reason'] = null;
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

        return back()->with('status', 'Información enviada. La cooperativa quedó pendiente de validación.');
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
