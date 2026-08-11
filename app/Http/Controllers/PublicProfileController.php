<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;

class PublicProfileController extends Controller
{
    /**
     * User-agents conocidos de rastreadores que arman una tarjeta de vista
     * previa al compartir un enlace (pedido explícito del usuario: "que el
     * mensaje a compartir por WhatsApp vaya el logo o perfil... algo
     * profesional"). Ninguno ejecuta JavaScript, así que las etiquetas
     * <meta og:*> que Profile/Show.vue agrega con Inertia<Head> nunca les
     * llegarían — esta app es una SPA sin SSR configurado.
     */
    private const LINK_PREVIEW_BOTS = '/WhatsApp|facebookexternalhit|Facebot|Twitterbot|LinkedInBot|Slackbot|TelegramBot|Discordbot/i';

    /**
     * Perfil público (sección 3.6): visible para cualquier usuario logueado,
     * no hace falta compartir flota — es justamente lo que permite evaluar a
     * alguien que todavía no conocés (un conductor público, o un cliente que
     * te invitó de la nada) antes de aceptar o invitar.
     */
    public function show(Request $request, User $user): Response|View
    {
        $user->load('driverProfile');

        $profileUrl = route('profiles.show', $user->id);

        // Para el puñado de rastreadores de vista previa, se sirve una
        // página mínima aparte con las etiquetas correctas (sin pasar por
        // Inertia, que no las mostraría a tiempo) — cualquier persona real
        // sigue viendo la app normal de siempre, esto nunca la reemplaza.
        if (preg_match(self::LINK_PREVIEW_BOTS, $request->userAgent() ?? '')) {
            $isDriver = $user->driverProfile !== null;
            $reviewCount = $user->reviewsReceived()->count();
            $averageRating = round((float) $user->reviewsReceived()->avg('rating'), 1);

            return view('profile-preview', [
                'title' => "{$user->name} — Arka01",
                'description' => ($isDriver ? 'Conductor' : 'Cliente').' en Arka01'
                    .($reviewCount > 0 ? " · ★ {$averageRating}" : '')
                    .' — movilidad de confianza en Ecuador.',
                'image' => $user->avatar_url && ! str_starts_with($user->avatar_url, 'http')
                    ? url($user->avatar_url)
                    : ($user->avatar_url ?? asset('icons/icon.svg')),
                'url' => $profileUrl,
            ]);
        }

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
                    // Confidencialidad (pedido explícito del usuario): la foto
                    // del vehículo ya no viaja a ninguna pantalla de cliente —
                    // solo el propio conductor y un admin la ven. La placa va
                    // tapada (ver DriverProfile::maskedPlate()); el tipo de
                    // vehículo (SUV, sedán, etc.) es el dato que la reemplaza.
                    'vehicle_make' => $user->driverProfile->vehicle_make,
                    'vehicle_model' => $user->driverProfile->vehicle_model,
                    'vehicle_type' => $user->driverProfile->vehicleTypeLabel(),
                    'vehicle_plate' => $user->driverProfile->maskedPlate(),
                    'rate_per_km' => $user->driverProfile->rate_per_km,
                    'accepts_cash' => $user->driverProfile->accepts_cash,
                    'accepts_transfer' => $user->driverProfile->accepts_transfer,
                    'verification_status' => $user->driverProfile->verification_status,
                ] : null,
            ],
            // Pedido explícito del usuario: para el código QR/enlace de
            // "compartir mi perfil" (Profile/Edit.vue) — absoluto, con
            // dominio, porque va a WhatsApp y a un lector de QR ajeno a la app.
            'profileUrl' => $profileUrl,
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
