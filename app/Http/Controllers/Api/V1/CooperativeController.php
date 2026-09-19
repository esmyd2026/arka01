<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Cooperative;
use App\Services\Cooperative\CooperativeDirectoryFinder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Directorio de cooperativas y red de cooperativas del cliente desde la app
 * móvil (roadmap Hito 5B, paridad con la web). Reusa
 * App\Services\Cooperative\CooperativeDirectoryFinder, la misma lógica que
 * CooperativeDirectoryController (web).
 */
class CooperativeController extends Controller
{
    public function __construct(private readonly CooperativeDirectoryFinder $directoryFinder) {}

    public function index(Request $request): JsonResponse
    {
        $cooperatives = $this->directoryFinder->browse(
            $request->user(),
            $request->string('q')->toString() ?: null,
            $request->filled('city_id') ? $request->integer('city_id') : null,
            (int) $request->input('page', 1),
        );

        return response()->json([
            'cooperatives' => $cooperatives->items(),
            'current_page' => $cooperatives->currentPage(),
            'last_page' => $cooperatives->lastPage(),
        ]);
    }

    public function show(Request $request, Cooperative $cooperative): JsonResponse
    {
        return response()->json($this->directoryFinder->showProfile($cooperative, $request->user()));
    }

    public function attach(Request $request, Cooperative $cooperative): JsonResponse
    {
        $this->directoryFinder->attach($request->user(), $cooperative);

        return response()->json(['message' => 'Cooperativa agregada a su red de confianza.']);
    }

    public function detach(Request $request, Cooperative $cooperative): JsonResponse
    {
        $this->directoryFinder->detach($request->user(), $cooperative);

        return response()->json(['message' => 'Cooperativa retirada de su red.']);
    }
}
