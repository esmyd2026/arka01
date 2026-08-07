<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DriverTier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pantalla de mantenimiento de las medallas del conductor (pedido explícito
 * del usuario): un admin define nombre, a partir de cuántos puntos aplica, y
 * si aparece en el directorio público — sin tocar código ni volver a
 * desplegar. Calcado de Admin\PlanController (mismo patrón, mismo espíritu).
 */
class DriverTierController extends Controller
{
    /** Los 8 colores válidos (ver resources/js/Utils/tierBadge.js) — nunca texto libre, el build de Tailwind no generaría la clase. */
    private const COLOR_KEYS = ['slate', 'orange', 'cyan', 'yellow', 'purple', 'blue', 'green', 'red'];

    public function index(): Response
    {
        return Inertia::render('Admin/DriverTiers', [
            'tiers' => DriverTier::query()->orderBy('min_points')->get(),
            'colorKeys' => self::COLOR_KEYS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateTier($request);

        DriverTier::query()->create($validated);

        return back()->with('status', 'Medalla creada.');
    }

    public function update(Request $request, DriverTier $driverTier): RedirectResponse
    {
        $validated = $this->validateTier($request, $driverTier);

        $driverTier->update($validated);

        return back()->with('status', 'Medalla actualizada.');
    }

    /**
     * La medalla de 0 puntos es el piso del sistema: sin ella,
     * DriverTier::forPoints() se queda sin ninguna fila con min_points=0 y
     * cualquier conductor con menos puntos que la siguiente medalla más baja
     * se quedaría sin ninguna aplicable. Mismo criterio que "el plan Gratis
     * no se puede eliminar" en Admin\PlanController.
     */
    public function destroy(DriverTier $driverTier): RedirectResponse
    {
        if ($driverTier->min_points === 0) {
            throw ValidationException::withMessages([
                'driver_tier' => 'La medalla de 0 puntos no se puede eliminar: es el piso del sistema, todo conductor tiene que caer en alguna.',
            ]);
        }

        $driverTier->delete();

        return back()->with('status', 'Medalla eliminada.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateTier(Request $request, ?DriverTier $tier = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'min_points' => [
                'required', 'integer', 'min:0',
                Rule::unique('driver_tiers', 'min_points')->ignore($tier?->id),
            ],
            'badge_emoji' => ['nullable', 'string', 'max:10'],
            'color_key' => ['required', Rule::in(self::COLOR_KEYS)],
            'is_public_eligible' => ['boolean'],
        ]);

        // Un checkbox sin marcar no manda el campo — sin este default,
        // "sacar" el tilde en el form de edición no se guardaría nunca.
        $validated['is_public_eligible'] ??= false;

        return $validated;
    }
}
