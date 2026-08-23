<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Auth\RegisteredUserController;
use App\Models\ClientCooperative;
use App\Models\Cooperative;
use App\Models\Fleet;
use App\Models\User;
use App\Rules\ValidPhoneNumberLocal;
use App\Services\WhatsAppVerificationSender;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GuestRideController extends Controller
{
    /**
     * Crea un acceso ligero para pedir una carrera sin correo ni contraseña.
     * No es anónimo absoluto: el teléfono se valida para que la cooperativa
     * pueda contactar al pasajero y para limitar solicitudes falsas.
     */
    public function store(Request $request): RedirectResponse
    {
        // Pedido explícito del usuario: si escribe el 0 inicial (ej.
        // "0988492339"), se lo quitamos solo en vez de rechazarlo.
        $request->merge([
            'phone_local' => ValidPhoneNumberLocal::normalize($request->input('country_code'), $request->input('phone_local')),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'country_code' => ['required', 'string', Rule::in(RegisteredUserController::COUNTRY_CODES)],
            'phone_local' => ['required', 'string', new ValidPhoneNumberLocal],
            'cooperative_id' => ['required', 'integer', 'exists:cooperatives,id'],
            'origin_address' => ['required', 'string', 'max:255'],
            'origin_lat' => ['required', 'numeric', 'between:-90,90'],
            'origin_lng' => ['required', 'numeric', 'between:-180,180'],
            'destination_address' => ['required', 'string', 'max:255'],
            'destination_lat' => ['required', 'numeric', 'between:-90,90'],
            'destination_lng' => ['required', 'numeric', 'between:-180,180'],
            // Campo trampa invisible: los navegadores normales lo dejan
            // vacío; bots genéricos suelen rellenarlo automáticamente.
            'website' => ['nullable', 'string', 'max:0'],
        ]);

        $cooperative = Cooperative::query()
            ->whereKey($validated['cooperative_id'])
            ->where('status', 'approved')
            ->whereNull('suspended_at')
            ->first();

        if (! $cooperative) {
            throw ValidationException::withMessages([
                'cooperative_id' => 'Esta cooperativa no está disponible en este momento.',
            ]);
        }

        $phone = $validated['country_code'].$validated['phone_local'];
        $phoneAttemptKey = 'guest-ride-phone:'.hash('sha256', $phone);

        if (RateLimiter::tooManyAttempts($phoneAttemptKey, 3)) {
            throw ValidationException::withMessages([
                'phone_local' => 'Espere unos minutos antes de volver a intentar con este número.',
            ]);
        }
        RateLimiter::hit($phoneAttemptKey, 600);

        if (User::query()->where('phone', $phone)->exists()) {
            throw ValidationException::withMessages([
                'phone_local' => 'Este número ya tiene una cuenta. Inicie sesión para continuar de forma segura.',
            ]);
        }

        if (! WhatsAppVerificationSender::enabled() && ! app()->environment(['local', 'testing'])) {
            throw ValidationException::withMessages([
                'phone_local' => 'El acceso como invitado está temporalmente fuera de servicio. Intente nuevamente en unos minutos.',
            ]);
        }

        $user = DB::transaction(function () use ($validated, $phone, $cooperative) {
            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => 'invitado-'.Str::uuid().'@guest.arka01.local',
                'phone' => $phone,
                'password' => Hash::make(Str::random(48)),
                'registration_lat' => $validated['origin_lat'],
                'registration_lng' => $validated['origin_lng'],
                'role' => 'cliente',
            ]);

            // El recorrido público ya explica lo esencial; evitamos que el
            // tutorial tape la confirmación de la carrera apenas ingresa.
            $user->forceFill([
                'email_verified_at' => now(),
                'onboarding_completed_at' => now(),
            ])->save();

            Fleet::query()->create([
                'owner_user_id' => $user->id,
                'name' => 'Viajes como invitado',
            ]);

            ClientCooperative::query()->firstOrCreate([
                'client_user_id' => $user->id,
                'cooperative_id' => $cooperative->id,
            ]);

            return $user;
        });

        $rideUrl = route('ride-requests.create', [
            'origin_address' => $validated['origin_address'],
            'origin_lat' => $validated['origin_lat'],
            'origin_lng' => $validated['origin_lng'],
            'destination_address' => $validated['destination_address'],
            'destination_lat' => $validated['destination_lat'],
            'destination_lng' => $validated['destination_lng'],
            'cooperativa' => $cooperative->id,
        ]);
        $phoneCodeSent = false;
        if (WhatsAppVerificationSender::enabled()) {
            $code = $user->issuePhoneVerificationCode();
            $phoneCodeSent = WhatsAppVerificationSender::sendCode($phone, $code);
        }

        // En producción nunca se omite la validación: si Meta está caído o
        // mal configurado, se revierte la cuenta provisional para impedir
        // cuentas masivas y para que el teléfono pueda reintentarlo después.
        if (! $phoneCodeSent && ! app()->environment(['local', 'testing'])) {
            $user->delete();
            throw ValidationException::withMessages([
                'phone_local' => 'No pudimos enviar el código ahora. Intente nuevamente en unos minutos.',
            ]);
        }

        if (! $phoneCodeSent) {
            // Solo local/tests: permite desarrollar sin credenciales Meta.
            $user->forceFill([
                'phone_verified_at' => now(),
                'phone_verification_code' => null,
                'phone_verification_expires_at' => null,
            ])->save();
        }

        event(new Registered($user));
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('url.intended', $rideUrl);

        if ($phoneCodeSent) {
            return redirect()->route('phone.verify.show')
                ->with('status', 'Le enviamos un código por WhatsApp para proteger su solicitud.');
        }

        return redirect()->to($rideUrl)
            ->with('status', 'Acceso como invitado listo. Revise el precio y confirme su carrera.');
    }
}
