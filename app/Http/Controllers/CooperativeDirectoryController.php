<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\Cooperative;
use App\Services\Cooperative\CooperativeDirectoryFinder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lógica real en App\Services\Cooperative\CooperativeDirectoryFinder
 * (roadmap app móvil, "full backend").
 */
class CooperativeDirectoryController extends Controller
{
    public function __construct(private readonly CooperativeDirectoryFinder $directoryFinder) {}

    public function index(Request $request): Response
    {
        $cooperatives = $this->directoryFinder->browse(
            $request->user(),
            $request->string('q')->toString() ?: null,
            $request->filled('city_id') ? $request->integer('city_id') : null,
            (int) $request->input('page', 1),
        )->withQueryString();

        return Inertia::render('Cooperative/Directory', [
            'cooperatives' => $cooperatives,
            'filters' => $request->only(['q', 'city_id']),
            'cities' => City::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Request $request, Cooperative $cooperative): Response
    {
        $data = $this->directoryFinder->showProfile($cooperative, $request->user());

        return Inertia::render('Cooperative/Show', $data);
    }

    public function attach(Request $request, Cooperative $cooperative): RedirectResponse
    {
        $this->directoryFinder->attach($request->user(), $cooperative);

        return back()->with('status', 'Cooperativa agregada a su red de confianza.');
    }

    public function detach(Request $request, Cooperative $cooperative): RedirectResponse
    {
        $this->directoryFinder->detach($request->user(), $cooperative);

        return back()->with('status', 'Cooperativa retirada de su red.');
    }
}
