<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Verificación de identidad de conductores (sección 9.5-C): aprobar o
 * rechazar la licencia y la foto del vehículo antes de que el conductor
 * pueda aparecer como "verificado" en su perfil público (sección 8).
 */
class DriverVerificationController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/DriverVerifications', [
            // Rendimiento de cara a producción (pedido explícito del
            // usuario): tope duro en vez de traer sin límite — no justifica
            // paginación de verdad (pantalla de admin, cola de revisión
            // manual, no un listado que un usuario final recorra).
            'pending' => DriverProfile::query()
                ->where('verification_status', 'pending')
                ->whereNotNull('license_photo_path')
                ->with('user')
                ->latest('updated_at')
                ->limit(200)
                ->get(),
        ]);
    }

    public function approve(Request $request, DriverProfile $driverProfile): RedirectResponse
    {
        $driverProfile->update([
            'verification_status' => 'approved',
            'verification_rejection_reason' => null,
            'verified_at' => now(),
            'verified_by' => $request->user()->id,
        ]);

        return back()->with('status', 'Conductor verificado.');
    }

    /**
     * Pedido explícito del usuario: si se rechaza, el admin tiene que dejar
     * asentado el motivo — sin esto, el conductor solo veía "Rechazada" sin
     * saber qué corregir antes de volver a subir sus fotos.
     */
    public function reject(Request $request, DriverProfile $driverProfile): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $driverProfile->update([
            'verification_status' => 'rejected',
            'verification_rejection_reason' => $validated['reason'],
            'verified_at' => null,
        ]);

        return back()->with('status', 'Verificación rechazada.');
    }
}
