<?php

namespace App\Http\Controllers;

use App\Services\Driver\DriverDirectoryFinder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lógica real en App\Services\Driver\DriverDirectoryFinder (roadmap app
 * móvil, "full backend").
 */
class DriverDirectoryController extends Controller
{
    /** Pedido explícito del usuario: "buscar conductores para mi flota" es del lado cliente. */
    private const SINGLE_ROLE_MESSAGE = 'Los conductores no tienen un directorio propio para buscar — cada cuenta es cliente o conductor, no ambas.';

    public function __construct(private readonly DriverDirectoryFinder $directoryFinder) {}

    /**
     * Directorio de conductores públicos (sección 3.4). Ordena por cercanía
     * si el navegador comparte ubicación, si no por mejor calificados.
     */
    public function index(Request $request): Response|RedirectResponse
    {
        // Bug reportado por el usuario (caso real: un conductor terminó con
        // una flota propia fantasma, y su perfil público le mostraba la
        // insignia de "Cliente" por eso).
        if ($request->user()->isDriver()) {
            return redirect()->route('dashboard')->with('status', self::SINGLE_ROLE_MESSAGE);
        }

        $data = $this->directoryFinder->browse(
            $request->user(),
            $request->float('lat') ?: null,
            $request->float('lng') ?: null,
            (int) $request->input('page', 1),
        );

        return Inertia::render('Directory/Index', [
            'drivers' => $data['drivers'],
            'targetFleetId' => $data['targetFleetId'],
        ]);
    }
}
