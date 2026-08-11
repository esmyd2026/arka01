<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Auth\RegisteredUserController;
use App\Models\DriverProfile;
use App\Models\DriverTier;
use App\Models\PricingSetting;
use App\Models\User;
use App\Rules\ValidPhoneNumberLocal;
use App\Services\PlanLimits;
use App\Services\WhatsAppConfig;
use App\Services\WhatsAppVerificationSender;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DriverProfileController extends Controller
{
    /** Cada cuenta es cliente o conductor, nunca las dos (sección 3.1). */
    private const SINGLE_ROLE_MESSAGE = 'No puede activarse como conductor: esta cuenta ya es cliente (tiene una flota propia). Cada cuenta es cliente o conductor, no ambas.';

    public function __construct(private readonly PlanLimits $planLimits) {}

    /**
     * Muestra el formulario de "Convertirme en conductor" (módulo de registro del
     * conductor, sección 9.5-B). Si el usuario ya tiene perfil, este mismo formulario
     * sirve para editarlo — no hace falta una pantalla separada.
     */
    public function edit(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if (! $user->isDriver() && $user->fleets()->exists()) {
            return redirect()->route('dashboard')->with('status', self::SINGLE_ROLE_MESSAGE);
        }

        // Avisos de carrera nueva por WhatsApp (pedido explícito del
        // usuario): la pantalla usa esto para mostrar el estado de la
        // ventana de 24h y el link "wa.me" para abrirla/renovarla.
        $whatsappSession = $user->currentWhatsAppSession();

        // Medallas por puntos (pedido explícito del usuario): progreso hacia
        // el directorio público, para que el conductor sepa qué le falta —
        // ver App\Models\DriverTier.
        $totalPoints = $user->driverProfile->total_points ?? 0;
        $nextPublicTier = DriverTier::query()
            ->where('is_public_eligible', true)
            ->where('min_points', '>', $totalPoints)
            ->orderBy('min_points')
            ->first();

        return Inertia::render('Driver/Profile', [
            'driverProfile' => $request->user()->driverProfile,
            // La pantalla usa esto para mostrar (o no) el toggle de directorio
            // público, según si el plan vigente lo habilita (sección 7.2).
            'planLimits' => $this->planLimits->forDriver($request->user()),
            'totalPoints' => $totalPoints,
            'tier' => DriverTier::forPoints($totalPoints)->toBadge(),
            'nextPublicTier' => $nextPublicTier?->toBadge(),
            'whatsappSession' => $whatsappSession ? [
                'status' => $whatsappSession->status(),
                'expires_at' => $whatsappSession->expires_at->toIso8601String(),
            ] : null,
            'whatsappBusinessNumber' => WhatsAppConfig::businessNumber(),
            // Pedido explícito del usuario: el conductor tiene que poder ver
            // y corregir el número que declaró — es el que se valida contra
            // el que usa para conectarse por WhatsApp.
            'currentPhone' => $user->phone,
            'phoneVerified' => $user->phone_verified_at !== null,
            // Pedido explícito del usuario: la tarifa mínima que el
            // conductor declara acá no puede superar la de la plataforma
            // (/admin/tarifas) — se muestra como tope junto al campo, y
            // update() la rechaza si la supera (ver PriceCalculator para
            // dónde se aplica esta jerarquía en el cálculo del precio).
            'platformMinimumFare' => (float) PricingSetting::current()->minimum_fare,
        ]);
    }

    /**
     * Crea o actualiza el perfil de conductor del usuario autenticado. Cada
     * cuenta es cliente o conductor, nunca las dos (sección 3.1) — si ya es
     * dueño de una flota propia, no puede activarse como conductor acá.
     */
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->isDriver() && $user->fleets()->exists()) {
            throw ValidationException::withMessages(['license_number' => self::SINGLE_ROLE_MESSAGE]);
        }

        // Tope de la tarifa mínima propia del conductor (pedido explícito
        // del usuario): puede declarar una MENOR a la de la plataforma (la
        // plataforma la respeta en el cálculo del precio, ver
        // PriceCalculator::suggestedPrice()), pero no una mayor — si no, un
        // conductor podría inflar el piso de sus carreras por encima de lo
        // que el admin definió como base general.
        $adminMinimumFare = (float) PricingSetting::current()->minimum_fare;

        $validated = $request->validate([
            // Pedido explícito del usuario: el conductor puede corregir/cambiar
            // el número que declaró — es el que se valida contra el número
            // desde el que escribe por WhatsApp (WhatsAppWebhookController) y
            // al que le llegan los avisos de carrera nueva. Opcional: si lo
            // deja en blanco, no se toca lo que ya tenía.
            'country_code' => ['nullable', 'string', Rule::in(RegisteredUserController::COUNTRY_CODES)],
            'phone_local' => ['nullable', 'string', new ValidPhoneNumberLocal],
            'license_number' => ['required', 'string', 'max:50'],
            // Datos del vehículo, TODOS obligatorios (pedido explícito del
            // usuario): hacen falta para que un cliente pueda filtrar por
            // cantidad de pasajeros y cajuela al pedir una carrera — un dato
            // a medias no sirve para eso. Ver DriverProfile::hasCompleteVehicleInfo()
            // y DriverLocationController::update() (no puede ponerse
            // disponible mientras falte alguno de estos).
            'vehicle_make' => ['required', 'string', 'max:50'],
            'vehicle_model' => ['required', 'string', 'max:50'],
            'vehicle_color' => ['required', 'string', 'max:30'],
            'vehicle_plate' => ['required', 'string', 'max:20'],
            'vehicle_year' => ['required', 'integer', 'min:1970', 'max:'.(date('Y') + 1)],
            'passenger_capacity' => ['required', 'integer', 'min:1', 'max:8'],
            'has_trunk' => ['required', 'boolean'],
            'rate_per_km' => ['required', 'numeric', 'min:0'],
            'minimum_fare' => ['nullable', 'numeric', 'min:0', 'max:'.$adminMinimumFare],
            // Zona de cobertura (pedido explícito del usuario): en blanco =
            // sin límite, sigue recibiendo solicitudes sin importar la distancia.
            'max_request_distance_km' => ['nullable', 'integer', 'min:1', 'max:500'],
            'accepts_cash' => ['boolean'],
            'accepts_transfer' => ['boolean'],
            // Visibilidad en el directorio público (sección 3.4), gateada por el
            // plan Plus/Pro/Institucional (sección 7.2). Se valida la forma acá,
            // pero el valor final se fuerza abajo según el plan real del usuario
            // — nunca confiamos en lo que mande el formulario para esto.
            'is_public' => ['boolean'],
            // Verificación visible antes de subir a un conductor público que no
            // se conoce (sección 8): foto del conductor y de la placa/vehículo.
            // Un admin las revisa después desde /admin/verificaciones.
            'license_photo' => ['nullable', 'image', 'max:4096'],
            'vehicle_photo' => ['nullable', 'image', 'max:4096'],
        ], [
            'minimum_fare.max' => 'La plataforma no permite superar $'.number_format($adminMinimumFare, 2).' como tarifa mínima de una carrera (tope general definido en /admin/tarifas). Puede dejarla en blanco o poner una menor.',
        ]);

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

        // Si el plan vigente no incluye visibilidad pública, se ignora lo que
        // haya mandado el formulario: no se puede activar pagando cero.
        $validated['is_public'] = $this->planLimits->forDriver($request->user())['public_visibility']
            && ($validated['is_public'] ?? false);

        $existingProfile = $request->user()->driverProfile;
        $reviewedDocuments = false;

        // Pedido explícito del usuario: mientras la documentación está "en
        // revisión", no se puede subir una foto nueva — recién se habilita de
        // nuevo si un admin la rechaza (ahí sí hace falta corregir y volver a
        // subir) o si todavía no había ninguna.
        if (($request->hasFile('license_photo') || $request->hasFile('vehicle_photo'))
            && $existingProfile && ! $existingProfile->canUploadDocuments()) {
            throw ValidationException::withMessages([
                'license_photo' => 'Su documentación está en revisión — no se puede volver a subir hasta que un admin la revise.',
            ]);
        }

        if ($request->hasFile('license_photo')) {
            // Auditoría de seguridad (pedido explícito del usuario): la
            // licencia es un documento de identidad — a diferencia de la
            // foto del vehículo (que sí es pública, se muestra en el
            // directorio para que un cliente vea qué auto es), esta va al
            // disco privado. Solo el propio conductor y un admin pueden
            // verla (ver DriverProfileController::licensePhoto()).
            if ($existingProfile?->license_photo_path) {
                Storage::disk('local')->delete($existingProfile->license_photo_path);
            }
            $validated['license_photo_path'] = $request->file('license_photo')->store('driver-documents', 'local');
            $reviewedDocuments = true;
        }
        unset($validated['license_photo']);

        if ($request->hasFile('vehicle_photo')) {
            if ($existingProfile?->vehicle_photo_path) {
                Storage::disk('public')->delete($existingProfile->vehicle_photo_path);
            }
            $validated['vehicle_photo_path'] = $request->file('vehicle_photo')->store('driver-documents', 'public');
            $reviewedDocuments = true;
        }
        unset($validated['vehicle_photo']);

        // Cambiar los documentos vuelve a poner la verificación en revisión —
        // lo que un admin ya había aprobado (o rechazado) era sobre la foto
        // anterior, y se limpia la observación de un rechazo previo.
        if ($reviewedDocuments) {
            $validated['verification_status'] = 'pending';
            $validated['verification_rejection_reason'] = null;
        }

        // updateOrCreate porque un usuario tiene, como mucho, un solo perfil de
        // conductor (la primera vez lo crea, después lo va editando acá mismo).
        DriverProfile::query()->updateOrCreate(
            ['user_id' => $request->user()->id],
            $validated,
        );

        return redirect()->route('driver.profile.edit');
    }

    /**
     * Sirve la foto de licencia desde el disco privado (auditoría de
     * seguridad, pedido explícito del usuario): es un documento de
     * identidad, no algo que cualquier usuario logueado deba poder ver con
     * solo adivinar/copiar la URL. Solo el propio conductor o un admin.
     */
    public function licensePhoto(Request $request, User $user): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless($request->user()->id === $user->id || $request->user()->isAdmin(), 403);

        $path = $user->driverProfile?->license_photo_path;
        abort_if(blank($path), 404);

        return Storage::disk('local')->response($path);
    }
}
