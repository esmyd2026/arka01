<?php

namespace App\Services\Driver;

use App\Http\Controllers\Auth\RegisteredUserController;
use App\Models\DriverProfile;
use App\Models\PricingSetting;
use App\Models\Ride;
use App\Models\User;
use App\Rules\ValidPhoneNumberLocal;
use App\Services\DriverVerificationRequirementRegistry;
use App\Services\PlanLimits;
use App\Services\SystemEventLogger;
use App\Services\WhatsAppVerificationSender;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Crea, actualiza o reactiva el perfil de conductor del usuario autenticado
 * — extraído de DriverProfileController::update() (roadmap app móvil, "full
 * backend": nunca duplicar una regla de negocio entre web y móvil). Toma el
 * Request completo (no solo un array validado) porque depende fuertemente
 * de él: archivos subidos, merge del teléfono normalizado, y la propia
 * llamada a $request->validate(). Web y móvil comparten exactamente el
 * mismo comportamiento, incluida la subida de archivos multipart.
 */
class DriverProfileUpdater
{
    /**
     * Pedido explícito del usuario ("pasarme a conductor... fácil"): activarse
     * como conductor ya no lo bloquea tener una flota propia como cliente —
     * cada cuenta sigue operando como cliente O conductor, nunca las dos a la
     * vez (User::isClient()/isDriver() son mutuamente excluyentes), pero
     * ahora sí se puede cambiar de uno a otro. Lo único que sigue bloqueando
     * el cambio es tener un viaje en curso — cambiar de rol a mitad de una
     * carrera sí sería un problema real.
     */
    public const ACTIVE_RIDE_MESSAGE = 'Tiene un viaje en curso — termínelo antes de cambiar de rol.';

    /**
     * Datos que identifican el vehículo y quedan fijados después del primer
     * guardado. Las comodidades no están aquí porque sí pueden cambiar con el
     * uso diario sin que el conductor haya cambiado de vehículo.
     */
    private const LOCKED_VEHICLE_FIELDS = [
        'vehicle_make',
        'vehicle_model',
        'vehicle_color',
        'vehicle_type',
        'vehicle_plate',
        'vehicle_year',
        'passenger_capacity',
        'has_trunk',
    ];

    public function __construct(private readonly PlanLimits $planLimits) {}

    public function update(Request $request): DriverProfile
    {
        $user = $request->user();
        abort_if($user->isCooperative(), 403);
        $existingProfile = $user->driverProfile;

        // Único bloqueo real que queda para cambiar de rol: un viaje en
        // curso. Cambiar de cliente a conductor a mitad de una carrera
        // propia sí sería un problema (ver ACTIVE_RIDE_MESSAGE).
        if (! $user->isDriver() && Ride::where('client_user_id', $user->id)->where('status', 'in_progress')->exists()) {
            throw ValidationException::withMessages(['vehicle_make' => self::ACTIVE_RIDE_MESSAGE]);
        }

        // Tope de la tarifa mínima propia del conductor (pedido explícito
        // del usuario): puede declarar una MENOR a la de la plataforma (la
        // plataforma la respeta en el cálculo del precio, ver
        // PriceCalculator::suggestedPrice()), pero no una mayor — si no, un
        // conductor podría inflar el piso de sus carreras por encima de lo
        // que el admin definió como base general.
        $adminMinimumFare = (float) PricingSetting::current()->minimum_fare;

        // Pedido explícito del usuario: si escribe el 0 inicial (ej.
        // "0988492339"), se lo quitamos solo en vez de rechazarlo.
        $request->merge([
            'phone_local' => ValidPhoneNumberLocal::normalize($request->input('country_code'), $request->input('phone_local')),
        ]);

        $validated = $request->validate([
            // Pedido explícito del usuario: el conductor puede corregir/cambiar
            // el número que declaró — es el que se valida contra el número
            // desde el que escribe por WhatsApp (WhatsAppWebhookController) y
            // al que le llegan los avisos de carrera nueva. Opcional: si lo
            // deja en blanco, no se toca lo que ya tenía.
            'country_code' => ['nullable', 'string', Rule::in(RegisteredUserController::COUNTRY_CODES)],
            'phone_local' => ['nullable', 'string', new ValidPhoneNumberLocal],
            'driver_type' => ['sometimes', 'required', 'string', Rule::in(['independent', 'public_transport'])],
            // Datos del vehículo, TODOS obligatorios (pedido explícito del
            // usuario): hacen falta para que un cliente pueda filtrar por
            // cantidad de pasajeros y cajuela al pedir una carrera — un dato
            // a medias no sirve para eso. Ver DriverProfile::hasCompleteVehicleInfo()
            // y DriverLocationController::update() (no puede ponerse
            // disponible mientras falte alguno de estos).
            'vehicle_make' => ['required', 'string', 'max:50'],
            'vehicle_model' => ['required', 'string', 'max:50'],
            'vehicle_color' => ['required', 'string', 'max:30'],
            // Confidencialidad (pedido explícito del usuario): es el único
            // dato del vehículo que se muestra tal cual al cliente en las
            // pantallas públicas — la foto y la placa completa ya no (ver
            // DriverProfile::vehicleTypes()/maskedPlate()).
            'vehicle_type' => ['required', 'string', Rule::in(array_keys(DriverProfile::vehicleTypes()))],
            'vehicle_plate' => ['required', 'string', 'max:20'],
            'vehicle_year' => ['required', 'integer', 'min:1970', 'max:'.(date('Y') + 1)],
            'passenger_capacity' => ['required', 'integer', 'min:1', 'max:8'],
            'has_trunk' => ['required', 'boolean'],
            // Comodidades opcionales y autodeclaradas. Solo aceptamos claves
            // del catálogo; la categoría final nunca llega desde este
            // formulario porque la decide administración.
            'vehicle_amenities' => ['sometimes', 'array'],
            'vehicle_amenities.*' => ['string', Rule::in(array_keys(DriverProfile::vehicleAmenities()))],
            'rate_per_km' => ['required', 'numeric', 'min:0'],
            'minimum_fare' => ['nullable', 'numeric', 'min:0', 'max:'.$adminMinimumFare],
            // Zona de cobertura (pedido explícito del usuario): en blanco =
            // sin límite, sigue recibiendo solicitudes sin importar la distancia.
            'max_request_distance_km' => ['nullable', 'integer', 'min:1', 'max:500'],
            'accepts_cash' => ['boolean'],
            'accepts_transfer' => ['boolean'],
            // Pedido explícito del usuario: seguro que lo proteja a él, a
            // los pasajeros y al vehículo — igual que los documentos de más
            // abajo, solo se EXIGE marcado al solicitar verificación (ver
            // $isSubmittingVerification más abajo), no en cada guardado
            // parcial del perfil.
            'has_insurance' => ['sometimes', 'boolean'],
            // Visibilidad en el directorio público (sección 3.4), gateada por el
            // plan Plus/Pro/Institucional (sección 7.2). Se valida la forma acá,
            // pero el valor final se fuerza abajo según el plan real del usuario
            // — nunca confiamos en lo que mande el formulario para esto.
            'is_public' => ['boolean'],
            // Pedido explícito del usuario ("mejoremos la privacidad de los
            // conductores"): distinto de is_public de arriba — esto no es un
            // beneficio de plan, es control de privacidad puro, disponible
            // para cualquiera. Ver PublicProfileController::show(), que lo
            // usa para mostrar (o bloquear) el detalle a quien no sea el
            // propio conductor ni un admin.
            'profile_public' => ['boolean'],
            // Verificación visible antes de subir a un conductor público que no
            // se conoce (sección 8): foto del conductor y de la placa/vehículo.
            // Un admin las revisa después desde /admin/verificaciones.
            'profile_photo' => ['nullable', 'image', 'max:4096'],
            'identity_document' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            'license_photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            'police_record' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ], [
            'minimum_fare.max' => 'La plataforma no permite superar $'.number_format($adminMinimumFare, 2).' como tarifa mínima de una carrera (tope general definido en /admin/tarifas). Puede dejarla en blanco o poner una menor.',
        ]);

        // Una vez guardado, cada dato identificador del vehículo queda
        // protegido también en el servidor. Así no basta con volver a
        // habilitar un input desde el navegador para modificarlo. En perfiles
        // antiguos incompletos solo se bloquean los valores que ya existen;
        // los pendientes siguen disponibles para terminar el registro.
        if ($existingProfile) {
            foreach (self::LOCKED_VEHICLE_FIELDS as $field) {
                $isLocked = $field === 'has_trunk'
                    ? $existingProfile->hasCompleteVehicleInfo()
                    : filled($existingProfile->{$field});

                if ($isLocked) {
                    // Se conserva siempre el valor persistido. Ignorar un
                    // valor alterado permite guardar teléfono, tarifas o
                    // comodidades sin que un payload antiguo bloquee toda la
                    // operación, pero el vehículo nunca cambia.
                    $validated[$field] = $existingProfile->{$field};
                }
            }
        }

        // Cambio de número de WhatsApp (pedido explícito del usuario, caso
        // real: un conductor necesitaba corregir el número declarado en su
        // perfil). 'country_code' y 'phone_local' no son campos de
        // DriverProfile — se procesan acá y se sacan de $validated antes de
        // llegar al updateOrCreate() de más abajo.
        if (filled($validated['phone_local'] ?? null)) {
            $newPhone = $validated['country_code'].$validated['phone_local'];

            if ($newPhone !== $user->phone) {
                // Pedido explícito del usuario (caso real visto en el
                // webhook: dos conductores distintos "conectando" el mismo
                // número): un número de WhatsApp es de una sola cuenta a la
                // vez — mismo criterio que el registro (RegisteredUserController),
                // acá con el mensaje puntual de "ya existe otro conductor".
                if (User::query()->where('phone', $newPhone)->where('id', '!=', $user->id)->exists()) {
                    throw ValidationException::withMessages([
                        'phone_local' => 'Ese número ya está registrado por otra cuenta de Arka01.',
                    ]);
                }

                $user->forceFill(['phone' => $newPhone, 'phone_verified_at' => null])->save();

                // Mismo criterio que el registro: si la verificación por
                // WhatsApp está configurada, hay que volver a confirmar el
                // número nuevo (EnsurePhoneIsVerified lo va a exigir en la
                // próxima pantalla que lo pida, ej. /dashboard); si no está
                // configurada, queda auto-verificado para no bloquear a nadie.
                if (WhatsAppVerificationSender::enabled()) {
                    $code = $user->issuePhoneVerificationCode();
                    $sent = WhatsAppVerificationSender::sendCode($user->phone, $code);
                    Log::info('Código de verificación enviado tras cambiar el número desde el perfil de conductor.', [
                        'user_id' => $user->id, 'enviado_por_whatsapp' => $sent,
                    ]);

                    // Bug crítico reportado por el usuario: si el envío
                    // falla de verdad, no puede quedar bloqueando la cuenta
                    // esperando un código que nunca va a llegar — mismo
                    // criterio que "no configurada".
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

        // Normalizar el JSON evita duplicados y hace estable la comparación
        // cuando el navegador manda los checks en un orden diferente.
        $validated['vehicle_amenities'] = collect($validated['vehicle_amenities'] ?? [])
            ->unique()
            ->sort()
            ->values()
            ->all();

        // Si el plan vigente no incluye visibilidad pública, se ignora lo que
        // haya mandado el formulario: no se puede activar pagando cero.
        $validated['is_public'] = $this->planLimits->forDriver($user)['public_visibility']
            && ($validated['is_public'] ?? false);

        $reviewedDocuments = false;
        $isSubmittingVerification = ! $existingProfile
            || $request->hasFile('identity_document')
            || $request->hasFile('license_photo')
            || $request->hasFile('police_record');

        if ($isSubmittingVerification) {
            // Pedido explícito del usuario: "permiteme desde el admin poder
            // activar o no lo obligatorio para que el conductor se le haga
            // mas facil activarse" — cada uno de estos se puede apagar
            // desde /admin/sistema (ver DriverVerificationRequirementRegistry).
            foreach ([
                'identity_document' => 'identity_document_path',
                'license_photo' => 'license_photo_path',
                'police_record' => 'police_record_path',
            ] as $input => $pathField) {
                if (DriverVerificationRequirementRegistry::isRequired($input)
                    && ! $request->hasFile($input) && blank($existingProfile?->{$pathField})) {
                    throw ValidationException::withMessages([
                        $input => 'Este documento es obligatorio para solicitar la verificación.',
                    ]);
                }
            }

            // Pedido explícito del usuario: seguro que lo proteja a él, a
            // los pasajeros y al vehículo — autodeclarado, sin documento,
            // pero igual obligatorio para solicitar verificación (salvo que
            // un admin lo haya apagado).
            if (DriverVerificationRequirementRegistry::isRequired('has_insurance')
                && ! ($validated['has_insurance'] ?? $existingProfile?->has_insurance ?? false)) {
                throw ValidationException::withMessages([
                    'has_insurance' => 'Debe declarar que cuenta con un seguro vigente para solicitar la verificación.',
                ]);
            }
        }

        // Pedido explícito del usuario: mientras la documentación está "en
        // revisión", no se puede subir una foto nueva — recién se habilita de
        // nuevo si un admin la rechaza (ahí sí hace falta corregir y volver a
        // subir) o si todavía no había ninguna.
        if (($request->hasFile('identity_document') || $request->hasFile('license_photo') || $request->hasFile('police_record'))
            && $existingProfile && ! $existingProfile->canUploadDocuments()) {
            throw ValidationException::withMessages([
                'license_photo' => 'Su documentación está en revisión — no se puede volver a subir hasta que un admin la revise.',
            ]);
        }

        if ($isSubmittingVerification && DriverVerificationRequirementRegistry::isRequired('profile_photo')
            && ! $user->avatar_path && ! $request->hasFile('profile_photo')) {
            throw ValidationException::withMessages([
                'profile_photo' => 'La fotografía de perfil es obligatoria para verificar a un conductor.',
            ]);
        }

        if ($request->hasFile('profile_photo')) {
            if ($user->avatar_path && ! str_starts_with($user->avatar_path, 'http')) {
                Storage::disk('public')->delete($user->avatar_path);
            }
            $user->forceFill(['avatar_path' => $request->file('profile_photo')->store('avatars', 'public')])->save();
        }
        unset($validated['profile_photo']);

        foreach ([
            'identity_document' => 'identity_document_path',
            'police_record' => 'police_record_path',
        ] as $input => $pathField) {
            if ($request->hasFile($input)) {
                if ($existingProfile?->{$pathField}) {
                    Storage::disk('local')->delete($existingProfile->{$pathField});
                }
                $validated[$pathField] = $request->file($input)->store('driver-documents', 'local');
                $reviewedDocuments = true;
            }
            unset($validated[$input]);
        }

        if ($request->hasFile('license_photo')) {
            // Auditoría de seguridad (pedido explícito del usuario): la
            // licencia es un documento de identidad, va al disco privado.
            // Solo el propio conductor y un admin pueden verla (ver
            // DriverProfileController::licensePhoto()). La foto del vehículo
            // usa el disco 'public' por el mismo motivo técnico de siempre
            // (necesita URL directa), pero desde el pedido de confidencialidad
            // del usuario NINGUNA pantalla de cliente la muestra — solo el
            // propio conductor y un admin (ver Admin\DriverVerifications,
            // Admin\UserProfile).
            if ($existingProfile?->license_photo_path) {
                Storage::disk('local')->delete($existingProfile->license_photo_path);
            }
            $validated['license_photo_path'] = $request->file('license_photo')->store('driver-documents', 'local');
            $reviewedDocuments = true;
        }
        unset($validated['license_photo']);

        // Administración aprueba una identidad y un vehículo concretos. Si
        // después cambian datos sensibles (licencia, placa, tipo o capacidad)
        // o la foto/documentación, se necesita una revisión nueva. Tarifas,
        // cobertura y formas de pago pueden cambiar sin perder aprobación.
        //
        // Bug real reportado por el usuario (con caso real: un conductor YA
        // verificado marcó/desmarcó una comodidad opcional del vehículo —
        // aire acondicionado, WiFi, etc. — y quedó forzado a esperar una
        // nueva verificación, desconectado mientras tanto): `vehicle_amenities`
        // vivía en esta misma lista, pero el propio formulario ya las
        // describe como "datos opcionales" que "ayudan a evaluar la
        // categoría, pero no la asignan automáticamente" — no son un dato de
        // identidad ni de seguridad del vehículo, así que cambiarlas no
        // debería tirar abajo una verificación ya aprobada. Sigue avisando a
        // administración (ver el aviso más abajo), solo que ya no bloquea.
        $reviewedFields = [
            'driver_type', 'vehicle_make', 'vehicle_model',
            'vehicle_color', 'vehicle_type', 'vehicle_plate', 'vehicle_year',
            'passenger_capacity', 'has_trunk',
        ];
        $reviewedInformationChanged = $existingProfile && collect($reviewedFields)->contains(
            fn (string $field) => array_key_exists($field, $validated)
                && (string) $validated[$field] !== (string) $existingProfile->{$field}
        );

        // Aviso liviano a administración cuando cambian las comodidades de un
        // conductor YA verificado (pedido explícito del usuario: "que si me
        // llegue una alerta al admin pero no lo bloquee") — visible en
        // /admin/monitoreo, sin tocar `verification_status` ni sacarlo de
        // línea. Solo tiene sentido avisar si ya estaba aprobado: si todavía
        // está pendiente o rechazado, esto ya lo va a ver el admin en la cola
        // de verificaciones de siempre.
        if (
            $existingProfile
            && $existingProfile->verification_status === 'approved'
            && array_key_exists('vehicle_amenities', $validated)
            && collect($validated['vehicle_amenities'])->sort()->values()->all()
                !== collect($existingProfile->vehicle_amenities ?? [])->sort()->values()->all()
        ) {
            SystemEventLogger::log(
                eventType: 'driver_amenities_updated',
                module: 'driver_profile',
                message: "{$user->name} actualizó las comodidades de su vehículo (ya verificado) — no requiere una nueva revisión, solo FYI.",
                severity: 'info',
                context: [
                    'anteriores' => $existingProfile->vehicle_amenities ?? [],
                    'nuevas' => $validated['vehicle_amenities'],
                ],
                userId: $user->id,
            );
        }

        if ($reviewedDocuments || $request->hasFile('profile_photo') || $reviewedInformationChanged) {
            $validated['verification_status'] = 'pending';
            $validated['verification_rejection_reason'] = null;
            // Una revisión anterior ya no cubre los documentos nuevos. El
            // conductor queda fuera de línea hasta la nueva aprobación.
            $validated['verified_at'] = null;
            $validated['verified_by'] = null;
            $validated['is_available'] = false;

            // Una categoría describe el vehículo que fue revisado. Si cambia
            // información relevante, administración debe clasificarlo de
            // nuevo en vez de conservar una etiqueta potencialmente obsoleta.
            if ($reviewedInformationChanged) {
                $validated['service_category'] = null;
            }
        }

        // updateOrCreate porque un usuario tiene, como mucho, un solo perfil de
        // conductor (la primera vez lo crea, después lo va editando acá mismo).
        $profile = DriverProfile::query()->updateOrCreate(
            ['user_id' => $user->id],
            $validated,
        );

        // Pedido explícito del usuario ("pasarme a conductor, fácil"):
        // guardar este formulario siempre deja la cuenta como conductor
        // activo, incluso si el perfil venía pausado — no hace falta un
        // paso aparte de "reactivar" si ya está acá revisando sus datos.
        // deactivated_at nunca es un campo del formulario (no está en
        // $fillable), se limpia a mano.
        if ($profile->deactivated_at !== null) {
            $profile->forceFill(['deactivated_at' => null])->save();
        }

        return $profile;
    }
}
