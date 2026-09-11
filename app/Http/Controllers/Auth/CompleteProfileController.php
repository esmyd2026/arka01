<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Providers\RouteServiceProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Último paso del registro rápido por teléfono (ver QuickRegistrationController):
 * la cuenta ya existe y ya está logueada, solo falta el nombre y apellido que
 * el registro normal pide desde el arranque. Protegido por `auth` (ver
 * routes/auth.php) — App\Http\Middleware\EnsureProfileNameIsComplete es quien
 * manda para acá al resto de la app mientras falte este paso.
 */
class CompleteProfileController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        if ($request->user()->profile_name_completed_at) {
            return redirect(RouteServiceProvider::HOME);
        }

        return Inertia::render('Auth/CompleteProfile');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
        ]);

        $request->user()->forceFill([
            'name' => Str::squish($validated['first_name']),
            'last_name' => filled($validated['last_name'] ?? null) ? Str::squish($validated['last_name']) : null,
            'profile_name_completed_at' => now(),
        ])->save();

        return redirect(RouteServiceProvider::HOME);
    }
}
