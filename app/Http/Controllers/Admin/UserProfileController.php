<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Fleet;
use App\Models\Review;
use App\Models\User;
use App\Services\PlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pedido explícito del usuario: "en el Administrador debe existir una opción
 * para consultar el perfil completo tanto del conductor como del cliente,
 * mostrando toda la información relevante sin necesidad de navegar por
 * diferentes pantallas" — identidad, perfil de conductor (si tiene), flotas
 * como cliente (si tiene), suscripción de cada lado, y su reputación.
 */
class UserProfileController extends Controller
{
    public function __construct(private readonly PlanLimits $planLimits) {}

    public function show(User $user): Response
    {
        $user->load(['driverProfile.verifier', 'city']);

        $rating = round((float) $user->reviewsReceived()->avg('rating'), 1);
        $reviewCount = $user->reviewsReceived()->count();

        $recentReviews = Review::query()
            ->where('reviewee_user_id', $user->id)
            ->with(['reviewer', 'ride'])
            ->latest()
            ->limit(10)
            ->get();

        $fleetsOwned = $user->isClient()
            ? Fleet::query()
                ->where('owner_user_id', $user->id)
                ->withCount(['activeMembers as active_members_count'])
                ->get()
            : collect();

        return Inertia::render('Admin/UserProfile', [
            // locked_at está en User::$hidden por defecto (auditoría de
            // seguridad: no debe verlo cualquiera) — acá sí hace falta,
            // es la única pantalla donde un admin ve si la cuenta está
            // bloqueada y puede reactivarla.
            'profileUser' => $user->makeVisible('locked_at'),
            'driverPlan' => $user->isDriver() ? $this->planLimits->forDriver($user) : null,
            'clientPlan' => $user->isClient() ? $this->planLimits->forClient($user) : null,
            'fleetsOwned' => $fleetsOwned,
            'averageRating' => $rating,
            'reviewCount' => $reviewCount,
            'recentReviews' => $recentReviews,
        ]);
    }

    /**
     * Reactiva una cuenta bloqueada (pedido explícito del usuario: el
     * bloqueo desde el aviso de "si no fue usted" es una vía de un solo
     * sentido para el propio usuario — reactivarla queda a propósito como
     * una acción de admin, no algo que se pueda deshacer solo con el mismo
     * link, ver App\Http\Controllers\Auth\SessionTakeoverController::lock()).
     */
    public function unlock(User $user): RedirectResponse
    {
        $user->forceFill(['locked_at' => null])->save();

        Log::info('Cuenta reactivada por un admin.', ['user_id' => $user->id]);

        return back()->with('status', 'Cuenta reactivada.');
    }
}
