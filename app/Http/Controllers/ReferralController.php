<?php

namespace App\Http\Controllers;

use App\Models\DriverProfile;
use App\Models\Fleet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Referí a tu conductor" (pedido explícito del usuario): un cliente
 * comparte un enlace público con su código de invitación (el mismo
 * `invite_code` que ya existe para el conductor, sección 3.2 y 9.5) para que
 * otras personas —tengan o no cuenta todavía— lo agreguen a su propia flota.
 * Reutiliza el mismo `invite_code`, no genera uno nuevo por cada referido:
 * es el conductor el que se recomienda, no un cupón de quien comparte.
 */
class ReferralController extends Controller
{
    /**
     * Landing pública (sin sesión): cualquiera puede ver a quién le están
     * recomendando antes de decidir crear una cuenta — recién si ya está
     * logueado como cliente puede mandar la invitación de verdad (ver store()).
     */
    public function show(Request $request, DriverProfile $driverProfile): Response
    {
        $driverProfile->load('user');

        $rating = round((float) $driverProfile->user->reviewsReceived()->avg('rating'), 1);
        $reviewCount = $driverProfile->user->reviewsReceived()->count();

        $alreadyMember = false;
        if ($request->user()?->isClient()) {
            $alreadyMember = Fleet::query()
                ->where('owner_user_id', $request->user()->id)
                ->whereHas('activeMembers', fn ($query) => $query->where('driver_user_id', $driverProfile->user_id))
                ->exists();
        }

        return Inertia::render('Referral/Show', [
            'driver' => [
                'user_id' => $driverProfile->user_id,
                'name' => $driverProfile->user->name,
                'avatar_url' => $driverProfile->user->avatar_url,
                'vehicle_make' => $driverProfile->vehicle_make,
                'vehicle_model' => $driverProfile->vehicle_model,
                'is_verified' => $driverProfile->verification_status === 'approved',
                'average_rating' => $rating,
                'review_count' => $reviewCount,
            ],
            'inviteCode' => $driverProfile->invite_code,
            // El visitante puede: no tener sesión (ve la landing + CTA de
            // login/registro), estar logueado pero no ser cliente (conductor
            // o admin — no aplica), o ser cliente (puede mandar la
            // invitación, salvo que el conductor ya sea parte de su flota).
            'canInvite' => (bool) $request->user()?->isClient(),
            'alreadyMember' => $alreadyMember,
        ]);
    }

    /**
     * Manda la invitación de verdad — reutiliza tal cual la lógica ya
     * existente de FleetInvitationController::store() (cupo del plan,
     * "ya es miembro", "ya invitado", notificación, broadcast) en vez de
     * duplicarla: solo resuelve a qué flota del cliente referente va y le
     * suma `driver_user_id` a la solicitud antes de reenviarla.
     */
    public function store(Request $request, DriverProfile $driverProfile): RedirectResponse
    {
        abort_unless($request->user()->isClient(), 403);

        $fleet = Fleet::query()->where('owner_user_id', $request->user()->id)->orderBy('id')->first()
            ?? Fleet::query()->create(['owner_user_id' => $request->user()->id, 'name' => 'Mi flota']);

        $request->merge(['driver_user_id' => $driverProfile->user_id]);

        return app(FleetInvitationController::class)->store($request, $fleet);
    }
}
