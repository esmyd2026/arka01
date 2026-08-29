<?php

namespace App\Http\Controllers;

use App\Models\DriverProfile;
use App\Models\DriverTier;
use App\Models\PricingSetting;
use App\Models\Ride;
use App\Models\User;
use App\Services\Driver\DriverProfileUpdater;
use App\Services\PlanLimits;
use App\Services\WhatsAppConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lógica real de actualizar el perfil en App\Services\Driver\DriverProfileUpdater
 * (roadmap app móvil, "full backend").
 */
class DriverProfileController extends Controller
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
    private const ACTIVE_RIDE_MESSAGE = 'Tiene un viaje en curso — termínelo antes de cambiar de rol.';

    public function __construct(
        private readonly PlanLimits $planLimits,
        private readonly DriverProfileUpdater $driverProfileUpdater,
    ) {}

    /**
     * Muestra el formulario de "Convertirme en conductor" (módulo de registro del
     * conductor, sección 9.5-B). Si el usuario ya tiene perfil (activo o pausado),
     * este mismo formulario sirve para editarlo/reactivarlo — no hace falta una
     * pantalla separada.
     */
    public function edit(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        abort_if($user->isCooperative(), 403);

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
            // La pantalla de perfil debe explicar exactamente la misma causa
            // que bloquea el switch y el endpoint de ubicación.
            'canConnect' => (bool) $user->driverProfile?->canBecomeAvailable(),
            'connectionBlockReason' => $user->driverProfile?->availabilityBlockReason(),
            // Pedido explícito del usuario: tarjeta de perfil "profesional"
            // arriba de todo, mismo lenguaje visual que la tarjeta de "Te
            // recomendaron viajar con..." (Referral/Show.vue).
            'averageRating' => round((float) $user->reviewsReceived()->avg('rating'), 1),
            'reviewCount' => $user->reviewsReceived()->count(),
            // Catálogo fijo para el selector de "Tipo de vehículo" (pedido
            // explícito del usuario) — ver DriverProfile::vehicleTypes().
            'vehicleTypes' => DriverProfile::vehicleTypes(),
            'vehicleAmenities' => DriverProfile::vehicleAmenities(),
            'serviceCategories' => DriverProfile::serviceCategories(),
            'publicDriverCategories' => DriverProfile::publicCategories(),
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
     * Crea, actualiza o reactiva el perfil de conductor del usuario
     * autenticado (pedido explícito del usuario: "pasarme a conductor,
     * fácil"). Si venía con el perfil pausado (isDeactivated()), guardar acá
     * ya lo deja activo de nuevo — no hace falta un paso aparte. Lógica real
     * en App\Services\Driver\DriverProfileUpdater.
     */
    public function update(Request $request): RedirectResponse
    {
        $this->driverProfileUpdater->update($request);

        return redirect()->route('driver.profile.edit');
    }

    /**
     * "Pasarme a cliente" (pedido explícito del usuario): pausa el perfil de
     * conductor sin borrar nada — vehículo, verificación, medallas y
     * suscripción de conductor quedan tal cual, listos para retomar. La
     * cuenta pasa a operar como cliente de inmediato (User::isDriver() da
     * false en cuanto se guarda esto).
     */
    public function deactivate(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->isDriver(), 404);

        if (Ride::where('driver_user_id', $user->id)->where('status', 'in_progress')->exists()) {
            throw ValidationException::withMessages(['driver' => self::ACTIVE_RIDE_MESSAGE]);
        }

        // Se apaga la disponibilidad de una — mismo motivo que al cerrar
        // sesión (AuthenticatedSessionController): un conductor "pausado" no
        // puede seguir mostrándose disponible para sus clientes.
        $user->driverProfile->forceFill([
            'deactivated_at' => now(),
            'is_available' => false,
        ])->save();

        Log::info('Conductor pasó a cliente (perfil de conductor pausado por su propia cuenta).', ['user_id' => $user->id]);

        return redirect()->route('dashboard')->with('status', 'Listo — ahora es cliente. Su perfil de conductor quedó guardado, puede volver a activarlo cuando quiera.');
    }

    /**
     * "Reactivar mi perfil de conductor" (pedido explícito del usuario):
     * atajo de un solo toque para quien pausó antes y quiere volver — no
     * hace falta llenar el formulario de nuevo, los datos siguen ahí.
     */
    public function reactivate(Request $request): RedirectResponse
    {
        $user = $request->user();
        $profile = $user->driverProfile;

        abort_if($profile === null, 404);

        if (! $user->isDriver() && Ride::where('client_user_id', $user->id)->where('status', 'in_progress')->exists()) {
            throw ValidationException::withMessages(['driver' => self::ACTIVE_RIDE_MESSAGE]);
        }

        $profile->forceFill(['deactivated_at' => null])->save();

        return redirect()->route('driver.profile.edit')->with('status', 'Listo — ya volvió a ser conductor.');
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

    /** Sirve cédula, licencia o récord policial desde almacenamiento privado. */
    public function document(Request $request, User $user, string $type): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless($request->user()->id === $user->id || $request->user()->isAdmin(), 403);

        $path = match ($type) {
            'identity' => $user->driverProfile?->identity_document_path,
            'license' => $user->driverProfile?->license_photo_path,
            'police-record' => $user->driverProfile?->police_record_path,
            default => null,
        };

        abort_if(blank($path), 404);

        return Storage::disk('local')->response($path);
    }
}
