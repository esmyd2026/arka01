<?php

namespace App\Http\Controllers;

use App\Models\SavedRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Mis rutas" (pedido explícito del usuario): guardar un origen+destino ya
 * usados, con un alias opcional (ej. "Casa", "Trabajo"), para tomarlos
 * directo la próxima vez desde Ride/Request.vue, sin escribir ni marcar nada
 * de nuevo. Independiente de pedir una carrera — se puede guardar una ruta
 * sin llegar a pedirla en ese momento.
 */
class SavedRouteController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            // Pedido explícito del usuario: "que no sea obligatorio" —
            // opcional, se identifica por las direcciones si se deja vacío.
            'alias' => ['nullable', 'string', 'max:50'],
            'origin_lat' => ['required', 'numeric', 'between:-90,90'],
            'origin_lng' => ['required', 'numeric', 'between:-180,180'],
            'origin_address' => ['nullable', 'string', 'max:255'],
            'origin_sector_id' => ['nullable', 'integer', 'exists:sectors,id'],
            'destination_lat' => ['required', 'numeric', 'between:-90,90'],
            'destination_lng' => ['required', 'numeric', 'between:-180,180'],
            'destination_address' => ['nullable', 'string', 'max:255'],
            'destination_sector_id' => ['nullable', 'integer', 'exists:sectors,id'],
        ]);

        $request->user()->savedRoutes()->create($validated);

        return back()->with('status', 'Ruta guardada.');
    }

    public function destroy(Request $request, SavedRoute $savedRoute): RedirectResponse
    {
        abort_unless($savedRoute->client_user_id === $request->user()->id, 403);

        $savedRoute->delete();

        return back()->with('status', 'Ruta eliminada.');
    }
}
