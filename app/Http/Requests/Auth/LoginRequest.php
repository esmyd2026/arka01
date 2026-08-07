<?php

namespace App\Http\Requests\Auth;

use App\Exceptions\ActiveSessionExistsException;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, Rule|array|string>
     */
    public function rules(): array
    {
        return [
            // Login múltiple (consideración agregada al alcance): un mismo
            // campo acepta correo, teléfono o el usuario autogenerado — se
            // resuelve en authenticate(), acá solo hace falta que no venga vacío.
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $user = $this->resolveUser();

        // Bloqueo de cuenta (pedido explícito del usuario): la cuenta se
        // pudo haber bloqueado desde el aviso de "si no fue usted" — se
        // corta acá, antes de intentar la contraseña, con un mensaje que no
        // confunda esto con una contraseña incorrecta.
        if ($user?->isLocked()) {
            throw ValidationException::withMessages([
                'login' => 'Esta cuenta está bloqueada por seguridad. Contáctenos para reactivarla.',
            ]);
        }

        // Sin "recordarme" a propósito (sección de seguridad agregada al
        // alcance): la sesión única por cuenta necesita que cada re-login
        // pase siempre por acá, nunca en silencio por un remember-token.
        try {
            $attempted = $user && Auth::attempt(['id' => $user->id, 'password' => $this->string('password')]);
        } catch (ActiveSessionExistsException $e) {
            throw ValidationException::withMessages(['login' => $e->getMessage()]);
        }

        if (! $attempted) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'login' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Resuelve a qué cuenta se refiere el campo "login" — correo, teléfono o
     * usuario autogenerado. Delegado a User::findByLoginIdentifier(), única
     * fuente de verdad (también la usa SessionTakeoverController, que
     * necesita identificar la cuenta de la misma forma exacta).
     */
    private function resolveUser(): ?User
    {
        return User::findByLoginIdentifier((string) $this->string('login'));
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'login' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('login')).'|'.$this->ip());
    }
}
