<?php

namespace App\Actions\Auth;

use App\Jobs\ResolveRegistrationNeighborhood;
use App\Mail\WelcomeMail;
use App\Models\City;
use App\Models\User;
use App\Services\Haversine;
use App\Services\WhatsAppVerificationSender;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Alta de una cuenta nueva — extraído de RegisteredUserController::store()
 * (roadmap Hito 2, riesgo "doble mantenimiento web/móvil": nunca duplicar
 * una regla de negocio en dos controladores) para que el registro móvil
 * (Api\V1\AuthController) valide y cree la cuenta exactamente igual:
 * duplicados de correo/teléfono, ciudad más cercana por coordenadas,
 * verificación de teléfono por WhatsApp, correo de bienvenida.
 *
 * A propósito NO incluye nada específico de un canal: ni redirects web, ni
 * la atribución de referido por sesión (esa es solo para limpiar el
 * recordado de OAuth, ver ReferralAttribution — el registro normal ya
 * asigna `referred_by_user_id` acá mismo con el campo `ref`), ni la
 * creación de Cooperative (el registro móvil, por ahora, solo cubre
 * cliente/conductor).
 */
class RegisterUser
{
    /**
     * @param  array{account_type: string, name?: ?string, first_name?: ?string, last_name?: ?string, email: string, phone: string, password: string, ref?: ?string, lat?: ?float, lng?: ?float}  $data
     *
     * @throws ValidationException
     */
    public function execute(array $data): User
    {
        if (User::where('email', $data['email'])->exists()) {
            throw ValidationException::withMessages([
                'email' => 'Ya existe una cuenta con este correo. ¿Ya tiene una cuenta? Inicie sesión.',
            ]);
        }

        if (User::where('phone', $data['phone'])->exists()) {
            throw ValidationException::withMessages([
                'phone_local' => 'Ese número de teléfono ya está registrado. ¿Ya tiene una cuenta? Inicie sesión.',
            ]);
        }

        $hasCoordinates = isset($data['lat'], $data['lng']);
        $city = $hasCoordinates ? $this->nearestCity((float) $data['lat'], (float) $data['lng']) : null;

        $fullName = filled($data['first_name'] ?? null)
            ? Str::squish($data['first_name'].' '.$data['last_name'])
            : Str::squish($data['name']);

        $referrer = isset($data['ref'])
            ? User::query()->where('public_id', $data['ref'])->first()
            : null;

        $user = User::create([
            'name' => $fullName,
            'email' => $data['email'],
            'phone' => $data['phone'],
            'password' => Hash::make($data['password']),
            'referred_by_user_id' => $referrer?->id,
            'city_id' => $city?->id,
            'registration_lat' => $hasCoordinates ? $data['lat'] : null,
            'registration_lng' => $hasCoordinates ? $data['lng'] : null,
            // Bug reportado por el usuario: "se estan registrando como
            // conductor y el sistema termina creandole como cliente" — ver
            // EnsureDriverOnboardingIsComplete.
            'intends_to_drive' => $data['account_type'] === 'conductor',
        ]);

        // A diferencia de una cuenta creada por Google (contraseña al azar
        // que nadie conoce), acá el usuario SÍ acaba de elegir la suya.
        $user->forceFill(['password_set_at' => now()])->save();

        event(new Registered($user));

        // Nombre del barrio/zona: informativo (panel admin), depende de un
        // servicio externo — se resuelve aparte, en cola, para no demorar
        // ni arriesgar el registro por eso.
        if ($hasCoordinates) {
            ResolveRegistrationNeighborhood::dispatch($user->id, (float) $data['lat'], (float) $data['lng']);
        }

        Log::info('Cuenta nueva registrada.', ['user_id' => $user->id, 'username' => $user->username, 'member_code' => $user->member_code]);

        // Un correo mal configurado o caído no debería tumbar el registro.
        try {
            Mail::to($user->email)->send(new WelcomeMail($user));
        } catch (\Throwable $e) {
            Log::warning('No se pudo enviar el correo de bienvenida.', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        // Verificación de teléfono por WhatsApp: si no está configurada, o
        // si el envío falla de verdad, el teléfono queda auto-verificado
        // para no bloquear a nadie por una integración caída (bug crítico
        // reportado por el usuario: antes quedaba esperando para siempre un
        // código que nunca iba a llegar).
        if (WhatsAppVerificationSender::enabled()) {
            $code = $user->issuePhoneVerificationCode();
            $sent = WhatsAppVerificationSender::sendCode($user->phone, $code);
            Log::info('Código de verificación de teléfono enviado al registrarse.', ['user_id' => $user->id, 'enviado_por_whatsapp' => $sent]);

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

        return $user;
    }

    /**
     * Ciudad más cercana a la ubicación real dada al registrarse — mismo
     * cálculo (Haversine) que OperationsController::notifyNearby() contra
     * conductores, acá contra el catálogo de `cities`.
     */
    private function nearestCity(float $lat, float $lng): ?City
    {
        return City::query()
            ->where('is_active', true)
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->get(['id', 'lat', 'lng'])
            ->sortBy(fn (City $city) => Haversine::distanceKm($lat, $lng, (float) $city->lat, (float) $city->lng))
            ->first();
    }
}
