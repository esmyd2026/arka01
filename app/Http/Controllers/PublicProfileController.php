<?php

namespace App\Http\Controllers;

use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class PublicProfileController extends Controller
{
    /**
     * Perfil público (sección 3.6): visible para cualquier usuario logueado,
     * no hace falta compartir flota — es justamente lo que permite evaluar a
     * alguien que todavía no conocés (un conductor público, o un cliente que
     * te invitó de la nada) antes de aceptar o invitar.
     */
    public function show(User $user): Response
    {
        $user->load('driverProfile');

        $reviews = $user->reviewsReceived()
            ->with('reviewer')
            ->latest()
            ->paginate(10);

        return Inertia::render('Profile/Show', [
            // Auditoría de seguridad (pedido explícito del usuario): esta
            // pantalla es visible para CUALQUIER usuario logueado con
            // cualquier ID en la URL (a propósito, sección 3.6 — no hace
            // falta compartir flota para ver un perfil público). Mandar el
            // modelo completo permitía enumerar a toda la base de usuarios:
            // email, teléfono, is_admin, y hasta los códigos de verificación
            // hasheados. Acá solo va lo que Profile/Show.vue de verdad
            // muestra — mismo criterio que ya usa
            // PublicRideTrackingController::publicPayload().
            'profileUser' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'member_code' => $user->member_code,
                'avatar_url' => $user->avatar_url,
                'driver_profile' => $user->driverProfile ? [
                    'vehicle_photo_url' => $user->driverProfile->vehicle_photo_url,
                    'vehicle_make' => $user->driverProfile->vehicle_make,
                    'vehicle_model' => $user->driverProfile->vehicle_model,
                    'vehicle_plate' => $user->driverProfile->vehicle_plate,
                    'rate_per_km' => $user->driverProfile->rate_per_km,
                    'accepts_cash' => $user->driverProfile->accepts_cash,
                    'accepts_transfer' => $user->driverProfile->accepts_transfer,
                    'verification_status' => $user->driverProfile->verification_status,
                ] : null,
            ],
            'averageRating' => round((float) $user->reviewsReceived()->avg('rating'), 1),
            'reviewCount' => $user->reviewsReceived()->count(),
            'reviews' => $reviews,
            // Bug reportado por el usuario (perfil público mostraba las dos
            // insignias "Cliente" y "Conductor" a la vez): `fleets()->exists()`
            // podía dar true para un conductor con una flota fantasma vieja
            // (ver el guard nuevo en DriverDirectoryController::index()) —
            // acá usamos el mismo criterio canónico que el resto de la app
            // (User::isClient(), sección 3.1: cada cuenta es una sola cosa,
            // nunca las dos) en vez de reinventar la pregunta.
            'isClient' => $user->isClient(),
            'isDriver' => $user->driverProfile !== null,
        ]);
    }
}
