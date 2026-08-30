<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use App\Models\User;
use App\Notifications\DriverVerificationResultPushNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Verificación de identidad de conductores (sección 9.5-C): aprobar o
 * rechazar la cédula, licencia y certificado de antecedentes antes de que el conductor
 * pueda aparecer como "verificado" en su perfil público (sección 8).
 */
class DriverVerificationController extends Controller
{
    public function index(): Response
    {
        $profilesInProgress = DriverProfile::query()
            ->where(fn ($query) => $query->whereNull('verification_status')->orWhere('verification_status', 'pending'))
            ->with('user')
            ->latest('updated_at')
            ->limit(250)
            ->get()
            ->each->append('registration_complete');

        return Inertia::render('Admin/DriverVerifications', [
            'registered' => User::query()
                ->where('intends_to_drive', true)
                ->whereDoesntHave('driverProfile')
                ->latest('updated_at')
                ->limit(100)
                ->get(['id', 'public_id', 'name', 'last_name', 'email', 'member_code', 'created_at']),
            'incomplete' => $profilesInProgress->reject->registration_complete->values(),
            'pending' => $profilesInProgress
                ->filter(fn (DriverProfile $profile) => $profile->verification_status === 'pending' && $profile->registration_complete)
                ->values(),
            'rejected' => DriverProfile::query()
                ->where('verification_status', 'rejected')
                ->with('user')
                ->latest('updated_at')
                ->limit(50)
                ->get(),
            'approved' => DriverProfile::query()
                ->where('verification_status', 'approved')
                ->with('user')
                ->latest('verified_at')
                ->limit(20)
                ->get(),
            'publicDriverCategories' => DriverProfile::publicCategories(),
            'serviceCategories' => DriverProfile::serviceCategories(),
            'vehicleAmenities' => DriverProfile::vehicleAmenities(),
        ]);
    }

    public function approve(Request $request, DriverProfile $driverProfile): RedirectResponse
    {
        if (! $driverProfile->hasCompleteRegistrationInformation()) {
            throw ValidationException::withMessages([
                'verification' => 'No se puede aprobar: faltan datos obligatorios del conductor, vehículo o documentos.',
            ]);
        }

        $validated = $request->validate([
            'public_category' => ['required', 'string', Rule::in(array_keys(DriverProfile::publicCategories()))],
            'service_category' => ['required', 'string', Rule::in(array_keys(DriverProfile::serviceCategories()))],
        ]);

        $driverProfile->update([
            'verification_status' => 'approved',
            'verification_rejection_reason' => null,
            'verified_at' => now(),
            'verified_by' => $request->user()->id,
            'public_category' => $validated['public_category'],
            'service_category' => $validated['service_category'],
        ]);

        $driverProfile->user->notify(new DriverVerificationResultPushNotification($driverProfile, true));

        return back()->with('status', 'Conductor aprobado. Enviamos la bienvenida por notificación y correo.');
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
            'verified_by' => $request->user()->id,
            'is_available' => false,
        ]);

        $driverProfile->user->notify(new DriverVerificationResultPushNotification($driverProfile, false));

        return back()->with('status', 'Verificación rechazada.');
    }
}
