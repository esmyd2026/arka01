<?php

namespace App\Services\Profile;

use App\Http\Controllers\Auth\RegisteredUserController;
use App\Models\User;
use App\Rules\ValidPhoneNumberLocal;
use App\Services\WhatsAppVerificationSender;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Editar los datos personales del usuario (nombre, apellido, fecha de
 * nacimiento, correo, ciudad, foto de perfil, teléfono) — extraído de
 * ProfileController::update() (roadmap app móvil, "full backend": nunca
 * duplicar una regla de negocio entre web y móvil). Toma el Request
 * completo por el mismo motivo que DriverProfileUpdater: depende de
 * hasFile()/file() para la foto, y de merge() para normalizar el teléfono
 * antes de validar.
 */
class ProfileUpdater
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(int $userId): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'birth_date' => ['nullable', 'date', 'before_or_equal:'.now()->subYears(18)->toDateString()],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($userId)],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'avatar' => ['nullable', 'image', 'max:4096'],
            'country_code' => ['nullable', 'string', Rule::in(RegisteredUserController::COUNTRY_CODES)],
            'phone_local' => ['nullable', 'string', new ValidPhoneNumberLocal],
        ];
    }

    public function update(Request $request): User
    {
        $user = $request->user();

        // Pedido explícito del usuario: si escribe el 0 inicial (ej.
        // "0988492339"), se lo quitamos solo en vez de rechazarlo.
        $request->merge([
            'phone_local' => ValidPhoneNumberLocal::normalize($request->input('country_code'), $request->input('phone_local')),
        ]);

        $validated = $request->validate(self::rules($user->id), [
            'avatar.image' => 'El archivo tiene que ser una imagen (JPG, PNG o similar).',
            'avatar.max' => 'La foto pesa demasiado — el máximo es 4 MB. Probá con una de menor resolución o comprimida.',
            'birth_date.before_or_equal' => 'La fecha ingresada debe corresponder a una persona de 18 años o más.',
        ]);

        // Foto de perfil: si suben una nueva, se borra la anterior del disco
        // 'public' — pero solo si era un archivo propio, nunca si venía de
        // Google (URL externa completa, ver User::getAvatarUrlAttribute()).
        if ($request->hasFile('avatar')) {
            if ($user->avatar_path && ! str_starts_with($user->avatar_path, 'http')) {
                Storage::disk('public')->delete($user->avatar_path);
            }
            $validated['avatar_path'] = $request->file('avatar')->store('avatars', 'public');
        }
        unset($validated['avatar']);

        // Cambio de número de teléfono — mismo mecanismo que
        // DriverProfileUpdater::update(): un código de 6 dígitos por
        // WhatsApp, el mismo que usa toda la app para "teléfono verificado".
        if (filled($validated['phone_local'] ?? null)) {
            $newPhone = $validated['country_code'].$validated['phone_local'];

            if ($newPhone !== $user->phone) {
                if (User::query()->where('phone', $newPhone)->where('id', '!=', $user->id)->exists()) {
                    throw ValidationException::withMessages([
                        'phone_local' => 'Ese número ya está registrado por otra cuenta de Arka01.',
                    ]);
                }

                $user->forceFill(['phone' => $newPhone, 'phone_verified_at' => null])->save();

                if (WhatsAppVerificationSender::enabled()) {
                    $code = $user->issuePhoneVerificationCode();
                    $sent = WhatsAppVerificationSender::sendCode($user->phone, $code);
                    Log::info('Código de verificación enviado tras cambiar el número desde el perfil.', [
                        'user_id' => $user->id, 'enviado_por_whatsapp' => $sent,
                    ]);

                    if (! $sent) {
                        $user->forceFill([
                            'phone_verified_at' => now(),
                            'phone_verification_code' => null,
                            'phone_verification_expires_at' => null,
                        ])->save();
                    }
                } else {
                    $user->forceFill(['phone_verified_at' => now()])->save();
                }
            }
        }
        unset($validated['country_code'], $validated['phone_local']);

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return $user;
    }

    /**
     * Buscar a quién marcar como "quién lo recomendó" — a diferencia del
     * resto de buscadores de la app (solo código, por privacidad), acá el
     * propio usuario pidió poder buscar por nombre: es su elección sobre su
     * propia cuenta, no un listado público para invitar a nadie.
     */
    public function searchReferrer(User $user, string $term): Collection
    {
        $term = ltrim($term, '@');

        return User::query()
            ->where('id', '!=', $user->id)
            ->where(function ($query) use ($term) {
                $query->where('name', 'like', "%{$term}%")
                    ->orWhere('username', 'like', "%{$term}%");

                if (ctype_digit($term)) {
                    $query->orWhere('member_code', (int) $term);
                }
            })
            ->limit(10)
            ->get(['id', 'name', 'username', 'member_code']);
    }

    /**
     * Se fija una sola vez — no se puede pisar un referido ya asignado, sea
     * por la vía que haya sido (enlace, cupón, o esta misma búsqueda antes).
     */
    public function setReferrer(User $user, int $referrerUserId): void
    {
        if ($user->referred_by_user_id) {
            throw ValidationException::withMessages([
                'referrer_user_id' => 'Ya tiene un referido asignado — no se puede cambiar.',
            ]);
        }

        if ($referrerUserId === $user->id) {
            throw ValidationException::withMessages([
                'referrer_user_id' => 'No puede marcarse a sí mismo como referido.',
            ]);
        }

        $user->forceFill(['referred_by_user_id' => $referrerUserId])->save();
    }
}
