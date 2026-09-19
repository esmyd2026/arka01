<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Ride;
use App\Services\Security\SosAlertSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Botón SOS desde la app móvil (roadmap app móvil, "full backend" —
 * paridad con la web). Reusa App\Services\Security\SosAlertSender, la
 * misma lógica que SosAlertController (web).
 */
class SosAlertController extends Controller
{
    public function __construct(private readonly SosAlertSender $sosAlertSender) {}

    public function store(Request $request, Ride $ride): JsonResponse
    {
        $result = $this->sosAlertSender->trigger($ride, $request->user());

        return response()->json([
            'notified_contacts_count' => $result['notified'],
        ], 201);
    }
}
