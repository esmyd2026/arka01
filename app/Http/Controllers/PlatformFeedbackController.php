<?php

namespace App\Http\Controllers;

use App\Models\PlatformFeedback;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * "Ayúdanos a mejorar ARKA01" (sección 14 del roadmap de mejoras): formulario
 * PÚBLICO, sin necesidad de sesión — nombre y correo son opcionales a
 * propósito, no hace falta identificarse para opinar.
 */
class PlatformFeedbackController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'type' => ['required', Rule::in(['sugerencia', 'problema', 'nueva_idea', 'otro'])],
            'comment' => ['required', 'string', 'max:2000'],
        ]);

        PlatformFeedback::query()->create($validated);

        return back()->with('status', '¡Gracias! Ya recibimos su opinión.');
    }
}
