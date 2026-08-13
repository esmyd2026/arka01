<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\City;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\PlanLimits;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function __construct(private readonly PlanLimits $planLimits) {}

    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => session('status'),
            // Pedido explícito del usuario: una tarjeta de perfil "profesional"
            // arriba de todo, mismo lenguaje visual que la tarjeta de "Te
            // recomendaron viajar con..." (Referral/Show.vue) — necesita su
            // propia reputación, igual que ese conductor la mostraba.
            'averageRating' => round((float) $user->reviewsReceived()->avg('rating'), 1),
            'reviewCount' => $user->reviewsReceived()->count(),
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
            'profileUrl' => route('profiles.show', $user->id),
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
        ]);
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
        $user = $request->user();
        $validated = $request->validated();

        // Foto de perfil (consideración agregada al alcance): si suben una
        // nueva, se borra la anterior del disco 'public' — pero solo si era
        // un archivo propio, nunca si venía de Google (URL externa completa,
        // ver User::getAvatarUrlAttribute()), porque ahí no hay nada que borrar.
        if ($request->hasFile('avatar')) {
            if ($user->avatar_path && ! str_starts_with($user->avatar_path, 'http')) {
                Storage::disk('public')->delete($user->avatar_path);
            }
            $validated['avatar_path'] = $request->file('avatar')->store('avatars', 'public');
        }
        unset($validated['avatar']);

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

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
