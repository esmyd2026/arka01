<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TrustedContact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Contactos de confianza desde la app móvil (roadmap app móvil, "full
 * backend" — paridad con la web). Sin servicio aparte: CRUD simple, mismas
 * validaciones que TrustedContactController (web).
 */
class TrustedContactController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['contacts' => $request->user()->trustedContacts()->latest()->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'relationship_label' => ['nullable', 'string', 'max:50'],
        ]);

        if (empty($validated['phone']) && empty($validated['email'])) {
            throw ValidationException::withMessages([
                'phone' => 'Deje al menos un teléfono o un correo para poder avisarle.',
            ]);
        }

        $contact = $request->user()->trustedContacts()->create($validated);

        return response()->json(['contact' => $contact], 201);
    }

    public function destroy(Request $request, TrustedContact $contact): JsonResponse
    {
        abort_unless($contact->user_id === $request->user()->id, 403);

        $contact->delete();

        return response()->json(['message' => 'Contacto eliminado.']);
    }
}
