<?php

namespace App\Http\Controllers;

use App\Services\Driver\DriverDirectoryFinder;
use Illuminate\Http\JsonResponse;
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
     * Mapa de "conductores cerca de mí" (pedido explícito del usuario:
     * "imaginate un mapa... casi cubriendo toda la pantalla con la ubicación
     * actual y una barra arriba que indique el radio... y que vayan
     * apareciendo los conductores cercanos") — reemplaza a la versión previa
     * de este mismo directorio (lista + filtro por sector): esa lista sigue
     * existiendo como servicio (DriverDirectoryFinder::browse(), la sigue
     * usando la app móvil), pero acá en la web el buscador ahora es el mapa
     * en vivo, no una lista paginada.
     *
     * La carga inicial no trae conductores todavía — el navegador recién
     * tiene la ubicación DESPUÉS de este render, así que el propio
     * Directory/Index.vue pide la primera tanda a nearby() ni bien la
     * consigue (ver ese archivo).
     */
    public function index(Request $request): Response|RedirectResponse
    {
        // Bug reportado por el usuario (caso real: un conductor terminó con
        // una flota propia fantasma, y su perfil público le mostraba la
        // insignia de "Cliente" por eso).
        if ($request->user()->isDriver()) {
            return redirect()->route('dashboard')->with('status', self::SINGLE_ROLE_MESSAGE);
        }

        return Inertia::render('Directory/Index');
    }

    /**
     * JSON puro, no Inertia (pedido explícito del usuario: mover el radio o
     * arrastrar el mapa tiene que sentirse fluido) — Directory/Index.vue lo
     * llama por fetch() cada vez que cambia el radio o el centro elegido,
     * sin recargar la página completa cada vez.
     */
    public function nearby(Request $request): JsonResponse
    {
        if ($request->user()->isDriver()) {
            abort(403, self::SINGLE_ROLE_MESSAGE);
        }

        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'radius_km' => ['nullable', 'numeric', 'min:0.5', 'max:25'],
        ]);

        $data = $this->directoryFinder->nearby(
            $request->user(),
            (float) $validated['lat'],
            (float) $validated['lng'],
            isset($validated['radius_km']) ? (float) $validated['radius_km'] : null,
        );

        return response()->json([
            'drivers' => $data['drivers']->values(),
            'targetFleetId' => $data['targetFleetId'],
            'radiusKm' => $data['radiusKm'],
        ]);
    }
}
