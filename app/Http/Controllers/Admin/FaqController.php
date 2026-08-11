<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Faq;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mantenimiento del catálogo de preguntas frecuentes del Centro de Ayuda
 * (sección 11 del roadmap de mejoras) — mismo criterio que
 * Admin\RatingReasonController: nada queda quemado en código.
 */
class FaqController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Faqs', [
            'faqs' => Faq::query()
                ->orderBy('audience')
                ->orderBy('category')
                ->orderBy('sort_order')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Faq::query()->create($this->validateFaq($request));

        return back()->with('status', 'Pregunta creada.');
    }

    public function update(Request $request, Faq $faq): RedirectResponse
    {
        $faq->update($this->validateFaq($request));

        return back()->with('status', 'Pregunta actualizada.');
    }

    public function destroy(Faq $faq): RedirectResponse
    {
        $faq->delete();

        return back()->with('status', 'Pregunta eliminada.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateFaq(Request $request): array
    {
        $validated = $request->validate([
            'audience' => ['required', Rule::in(['cliente', 'conductor', 'ambos'])],
            'category' => ['required', 'string', 'max:50'],
            'question' => ['required', 'string', 'max:200'],
            'answer' => ['required', 'string', 'max:2000'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        $validated['is_active'] ??= true;
        $validated['sort_order'] ??= 0;

        return $validated;
    }
}
