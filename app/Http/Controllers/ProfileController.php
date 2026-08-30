<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Requests\ProfileUpdateRequest;
use App\Models\City;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\PlanLimits;
use App\Services\Profile\ProfileUpdater;
use App\Services\Trust\TrustIndexCalculator;
use App\Services\WhatsAppConfig;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function __construct(
        private readonly PlanLimits $planLimits,
        private readonly TrustIndexCalculator $trustIndexCalculator,
        private readonly ProfileUpdater $profileUpdater,
    ) {}

    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();

        // Avisos de sus carreras por WhatsApp (pedido explícito del usuario:
        // "un botón que le invite a escribirle al chatbot... para que de
        // allí tomemos el número y puedan estar notificados de sus viajes")
        // — mismo mecanismo ya probado del lado del conductor
        // (DriverProfileController::edit()): estado de la ventana de 24h +
        // link para abrirla escribiéndole al número oficial.
        $whatsappSession = $user->currentWhatsAppSession();

        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => session('status'),
            'whatsappSession' => $whatsappSession ? [
                'status' => $whatsappSession->status(),
                'expires_at' => $whatsappSession->expires_at->toIso8601String(),
            ] : null,
            'whatsappBusinessNumber' => WhatsAppConfig::businessNumber(),
            // Pedido explícito del usuario ("que tambien pueda actualizar su
            // numero de telefono") — mismo catálogo de países que ya usa el
            // registro y el formulario de conductor.
            'countryCodes' => RegisteredUserController::COUNTRY_CODES,
            // Pedido explícito del usuario: una tarjeta de perfil "profesional"
            // arriba de todo, mismo lenguaje visual que la tarjeta de "Te
            // recomendaron viajar con..." (Referral/Show.vue) — necesita su
            // propia reputación, igual que ese conductor la mostraba.
            'averageRating' => round((float) $user->reviewsReceived()->avg('rating'), 1),
            'reviewCount' => $user->reviewsReceived()->count(),
            // Mismo índice que ve en su Círculo de confianza y que viaja en
            // el perfil compartido. Se calcula una sola vez con la misma
            // fuente para evitar puntajes distintos entre pantallas.
            'trustIndex' => $this->trustIndexCalculator->calculate($user),
            // Ciudad donde vive (consideración agregada al alcance): arranca
            // por defecto la solicitud de carrera en esa ciudad.
            'cities' => City::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            // "Mi suscripción" (consideración agregada al alcance): detalle
            // del plan vigente + llamado a la acción para cambiar, con la
            // sugerencia del próximo plan hacia arriba.
            'subscriptionSummary' => $this->subscriptionSummary($user),
            // "Compartir mi perfil" (pedido explícito del usuario: QR +
            // WhatsApp) — absoluto, con dominio, porque termina en un lector
            // de QR o un mensaje de WhatsApp, fuera de la app.
            'profileUrl' => route('profiles.show', $user->public_id),
            // Trazabilidad de referidos (pedido explícito del usuario): tabla
            // de quiénes se registraron a través de un enlace compartido por
            // este usuario — ver User::referrals().
            'referrals' => $user->referrals()
                ->latest()
                ->get(['id', 'name', 'role', 'created_at'])
                ->map(fn (User $referral) => [
                    'id' => $referral->id,
                    'name' => $referral->name,
                    'role' => $referral->isDriver() ? 'Conductor' : ($referral->isClient() ? 'Cliente' : 'Admin'),
                    'registered_at' => $referral->created_at->toIso8601String(),
                ]),
            // "¿Quién lo recomendó?" (pedido explícito del usuario): quién
            // quedó marcado como su referidor — por enlace al registrarse,
            // por un cupón con dueño, o guardado a mano acá mismo. Null
            // hasta que alguna de esas tres vías lo fije.
            'referredBy' => $user->referredBy()->first(['id', 'name', 'username', 'member_code']),
        ]);
    }

    /**
     * Buscar a quién marcar como "quién lo recomendó" (pedido explícito del
     * usuario: "que busquen en la plataforma quien los recomendo... por
     * nombres o usuario o codigo") — a diferencia de los buscadores del
     * resto de la app (solo código, por privacidad), acá el propio usuario
     * pidió poder buscar por nombre: es su elección sobre su propia cuenta,
     * no un listado público para invitar a nadie.
     */
    public function searchReferrer(Request $request): JsonResponse
    {
        $validated = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);

        return response()->json(['users' => $this->profileUpdater->searchReferrer($request->user(), $validated['q'])]);
    }

    /**
     * Se fija una sola vez (pedido explícito del usuario: "que le den a un
     * boton guardar y ya alli se quede quemado") — no se puede pisar un
     * referido ya asignado, sea por la vía que haya sido (enlace, cupón, o
     * esta misma búsqueda antes).
     */
    public function setReferrer(Request $request): RedirectResponse
    {
        $validated = $request->validate(['referrer_user_id' => ['required', 'integer', 'exists:users,id']]);

        $this->profileUpdater->setReferrer($request->user(), (int) $validated['referrer_user_id']);

        return back()->with('status', 'Guardado — ya quedó marcado quién lo recomendó.');
    }

    /**
     * @return array<string, array{current: array, next: array|null}>
     */
    private function subscriptionSummary(User $user): array
    {
        $summary = [];

        // Mismo criterio que la navegación (sección 3.1): el rol de conductor
        // necesita alta explícita, el de cliente es el implícito por defecto.
        if ($user->isDriver()) {
            $summary['driver'] = $this->planUpsell($user, 'driver');
        }

        if ($user->isClient()) {
            $summary['client'] = $this->planUpsell($user, 'client');
        }

        return $summary;
    }

    /**
     * @return array{current: array, next: array|null}
     */
    private function planUpsell(User $user, string $ownerType): array
    {
        $limits = $ownerType === 'driver' ? $this->planLimits->forDriver($user) : $this->planLimits->forClient($user);

        $currentPlan = SubscriptionPlan::query()
            ->where('owner_type', $ownerType)
            ->where('code', $limits['plan_code'])
            ->first();

        $nextPlan = $currentPlan
            ? SubscriptionPlan::query()
                ->where('owner_type', $ownerType)
                ->where('is_active', true)
                ->where('sort_order', '>', $currentPlan->sort_order)
                ->orderBy('sort_order')
                ->first()
            : null;

        return [
            'current' => $limits,
            'next' => $nextPlan ? [
                'name' => $nextPlan->name,
                'monthly_price' => $nextPlan->monthly_price,
                'price_diff' => round($nextPlan->monthly_price - (float) ($currentPlan->monthly_price ?? 0), 2),
                'benefit' => $this->benefitBlurb($ownerType, $currentPlan, $nextPlan),
            ] : null,
        ];
    }

    /**
     * Un renglón corto de "qué ganás" con el próximo plan — para el llamado a
     * la acción tipo "por $5 más, tené un plan con más oportunidades". Arma
     * una cláusula por cada dimensión que de verdad mejora (dos planes
     * pueden compartir un límite y diferir solo en otro, ej. Gratis→Plus de
     * cliente: mismas flotas, más conductores por flota) en vez de asumir
     * que siempre es el mismo campo el que cambia.
     */
    private function benefitBlurb(string $ownerType, ?SubscriptionPlan $current, SubscriptionPlan $next): string
    {
        $clauses = [];

        if ($ownerType === 'driver') {
            if ($next->max_clients === null && $current?->max_clients !== null) {
                $clauses[] = 'clientes de confianza ilimitados';
            } elseif ($next->max_clients !== null) {
                $diff = $next->max_clients - (int) ($current?->max_clients ?? 0);
                if ($diff > 0) {
                    $clauses[] = "hasta {$diff} cliente(s) de confianza más";
                }
            }

            if ($next->public_visibility && ! $current?->public_visibility) {
                $clauses[] = 'aparece en el directorio público';
            }

            if ($next->priority_listing && ! $current?->priority_listing) {
                $clauses[] = 'sale primero en el directorio';
            }

            if ($next->verified_badge && ! $current?->verified_badge) {
                $clauses[] = 'insignia de conductor verificado';
            }
        } else {
            if ($next->max_fleets === null && $current?->max_fleets !== null) {
                $clauses[] = 'flotas ilimitadas';
            } elseif ($next->max_fleets !== null) {
                $diff = $next->max_fleets - (int) ($current?->max_fleets ?? 0);
                if ($diff > 0) {
                    $clauses[] = "hasta {$diff} flota(s) más";
                }
            }

            if ($next->max_drivers_per_fleet === null && $current?->max_drivers_per_fleet !== null) {
                $clauses[] = 'conductores ilimitados por flota';
            } elseif ($next->max_drivers_per_fleet !== null) {
                $diff = $next->max_drivers_per_fleet - (int) ($current?->max_drivers_per_fleet ?? 0);
                if ($diff > 0) {
                    $clauses[] = "hasta {$diff} conductor(es) más por flota";
                }
            }
        }

        return $clauses === [] ? 'más beneficios' : implode(' y ', $clauses);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $this->profileUpdater->update($request);

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
