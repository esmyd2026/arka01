<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;

class ReferralAttribution
{
    private const SESSION_KEY = 'referral.referrer_user_id';

    /**
     * Conserva el referente de un enlace público durante todo el recorrido
     * de autenticación, incluido el salto externo de Google OAuth.
     */
    public function remember(Request $request): ?string
    {
        $publicId = trim((string) $request->query('ref'));
        $referrer = $publicId !== ''
            ? User::query()->where('public_id', $publicId)->first(['id', 'public_id'])
            : null;

        if ($referrer) {
            // La sesión conserva la llave interna; el navegador recibe solo
            // el UUID público al continuar entre login, registro y OAuth.
            $request->session()->put(self::SESSION_KEY, $referrer->id);
        }

        return User::query()->whereKey($request->session()->get(self::SESSION_KEY))->value('public_id');
    }

    /**
     * Asigna el referente una sola vez. Una cuenta que ya fue atribuida no
     * puede cambiar de referente al abrir otro enlace posteriormente.
     */
    public function attribute(Request $request, User $user): void
    {
        $referrerId = (int) $request->session()->pull(self::SESSION_KEY);

        if (! $referrerId || $user->referred_by_user_id || $referrerId === $user->id) {
            return;
        }

        if (User::query()->whereKey($referrerId)->exists()) {
            $user->forceFill(['referred_by_user_id' => $referrerId])->save();
        }
    }
}
