<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RatingReason;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mantenimiento del catálogo de "Motivos de Calificación" (pedido explícito
 * del usuario): agregar, editar, activar/desactivar los motivos que alguien
 * tiene que elegir al bajar una calificación de las 5 estrellas por defecto.
 */
class RatingReasonController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/RatingReasons', [
            'reasons' => RatingReason::query()
                ->orderBy('direction')
                ->orderBy('sort_order')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateReason($request);

        RatingReason::query()->create($validated);

        return back()->with('status', 'Motivo creado.');
    }

    public function update(Request $request, RatingReason $ratingReason): RedirectResponse
    {
        $validated = $this->validateReason($request, $ratingReason);

        $ratingReason->update($validated);

        return back()->with('status', 'Motivo actualizado.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateReason(Request $request, ?RatingReason $reason = null): array
    {
        $validated = $request->validate([
            'direction' => ['required', Rule::in(['client_to_driver', 'driver_to_client'])],
            'text' => ['required', 'string', 'max:150'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        $validated['is_active'] ??= true;
        $validated['sort_order'] ??= 0;

        return $validated;
    }
}
