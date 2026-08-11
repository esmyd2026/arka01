<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Trazabilidad de referidos (pedido explícito del usuario): quién invitó a
 * quién a registrarse — vía la invitación de flota de un conductor
 * (App\Http\Controllers\ReferralController) o el perfil público de
 * cualquiera (ShareProfileQr.vue) — ver User::referredBy()/referrals().
 */
class ReferralController extends Controller
{
    public function index(Request $request): Response
    {
        $query = User::query()
            ->whereNotNull('referred_by_user_id')
            ->with('referredBy')
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = $request->string('q');
                $query->where(fn ($query) => $query->where('name', 'like', "%{$term}%")
                    ->orWhereHas('referredBy', fn ($query) => $query->where('name', 'like', "%{$term}%")));
            })
            ->latest();

        $paginated = $query->paginate(20)->withQueryString();

        $paginated->through(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $this->roleLabel($user),
            'registered_at' => $user->created_at->toIso8601String(),
            'referred_by' => [
                'id' => $user->referredBy->id,
                'name' => $user->referredBy->name,
                'role' => $this->roleLabel($user->referredBy),
            ],
        ]);

        return Inertia::render('Admin/Referrals', [
            'referrals' => $paginated,
            'filters' => $request->only(['q']),
        ]);
    }

    private function roleLabel(User $user): string
    {
        return $user->isDriver() ? 'Conductor' : ($user->isClient() ? 'Cliente' : 'Admin');
    }
}
