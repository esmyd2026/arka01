<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformFeedback;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administración → Opiniones (sección 14 del roadmap de mejoras): revisar,
 * clasificar y hacer seguimiento a lo que mandan los visitantes desde
 * "Ayúdanos a mejorar ARKA01" en la página pública.
 */
class PlatformFeedbackController extends Controller
{
    public function index(Request $request): Response
    {
        $feedback = PlatformFeedback::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Admin/Feedback', [
            'feedback' => $feedback,
            'filters' => $request->only(['status', 'type']),
        ]);
    }

    public function update(Request $request, PlatformFeedback $platformFeedback): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['nueva', 'revisando', 'considerada', 'implementada', 'descartada'])],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $platformFeedback->update($validated);

        return back()->with('status', 'Opinión actualizada.');
    }
}
