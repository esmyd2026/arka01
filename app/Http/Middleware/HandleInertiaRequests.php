<?php

namespace App\Http\Middleware;

use App\Services\PlanLimits;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    public function __construct(private readonly PlanLimits $planLimits) {}

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user,
                // Cada cuenta es cliente O conductor, nunca las dos (ver
                // App\Models\User::isClient()/isDriver() y los chequeos de
                // exclusividad en FleetController/DriverProfileController).
                'isDriver' => $user?->isDriver() ?? false,
                'isClient' => $user?->isClient() ?? false,
                'isCooperative' => $user?->isCooperative() ?? false,
                'hasFleet' => $user ? $user->fleets()->exists() : false,
                // Para la insignia compacta "★4.5" del propio usuario en el
                // menú de cuenta (sección 3.6) — mismo criterio que el perfil
                // público (App\Http\Controllers\PublicProfileController).
                'averageRating' => $user ? round((float) $user->reviewsReceived()->avg('rating'), 1) : 0,
                'reviewCount' => $user ? $user->reviewsReceived()->count() : 0,
                // Plan vigente del rol activo, para mostrarlo debajo de la
                // calificación en el menú de cuenta (consideración agregada al
                // alcance).
                'plans' => $user ? [
                    'driver' => $user->isDriver() ? $this->planLimits->forDriver($user)['plan_name'] : null,
                    'client' => $user->isClient() ? $this->planLimits->forClient($user)['plan_name'] : null,
                    'cooperative' => $user->isCooperative() ? $this->planLimits->forCooperative($user)['plan_name'] : null,
                ] : null,
                // Pedido explícito del usuario: "que el conductor sepa
                // también en cuál está" — la insignia de verificado depende
                // de la aprobación del admin Y de que el plan la incluya
                // (mismo criterio que RideRequestController::driverCardData()
                // y DriverDirectoryController), para que se vea igual acá que
                // donde la ven los clientes.
                'hasVerifiedBadge' => $user?->isDriver()
                    ? $user->driverProfile?->verification_status === 'approved'
                        && $this->planLimits->forDriver($user)['verified_badge']
                    : null,
            ],
            // Notificaciones push (sección 9.2 y 9.5): el frontend la necesita
            // para suscribirse vía PushManager, nunca la llave privada.
            'vapidPublicKey' => config('webpush.vapid.public_key'),
            // El botón "Continuar con Google" del login solo se muestra si el
            // usuario ya completó las credenciales de OAuth en .env — así no
            // hay un botón roto mientras tanto.
            'googleLoginEnabled' => filled(config('services.google.client_id')) && filled(config('services.google.client_secret')),
            // Mensajes flash tipo ->with('status', '...'), compartidos acá
            // porque antes cada página tenía que pasarlos a mano (y casi
            // ninguna lo hacía) — ver banner en AuthenticatedLayout.vue.
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
            ],
        ];
    }
}
