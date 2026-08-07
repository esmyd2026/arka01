<?php

namespace App\Http\Controllers;

use App\Models\TrustedContact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Contactos de confianza (sección 8): a quién se avisa desde el botón SOS y
 * a quién se le puede compartir el enlace de seguimiento en vivo. No son
 * usuarios de la plataforma, solo datos de contacto.
 */
class TrustedContactController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Security/TrustedContacts', [
            'contacts' => $request->user()->trustedContacts()->latest()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
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

        $request->user()->trustedContacts()->create($validated);

        return back()->with('status', 'Contacto agregado.');
    }

    public function destroy(Request $request, TrustedContact $contact): RedirectResponse
    {
        if ($contact->user_id !== $request->user()->id) {
            abort(403);
        }

        $contact->delete();

        return back()->with('status', 'Contacto eliminado.');
    }
}
