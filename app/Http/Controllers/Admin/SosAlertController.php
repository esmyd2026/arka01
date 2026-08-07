<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SosAlert;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Auditoría de alertas SOS (sección 8 y 9.5-C): un admin solo mira el
 * registro — no hay acción a tomar acá, es historial para revisar disputas
 * o patrones de mal comportamiento.
 */
class SosAlertController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/SosAlerts', [
            'alerts' => SosAlert::query()
                ->with(['ride', 'triggeredBy'])
                ->latest()
                ->paginate(20),
        ]);
    }
}
