<?php

namespace App\Http\Controllers;

use App\Models\CooperativeDriverMembership;
use App\Models\CooperativeWalletEntry;
use App\Models\DriverBankAccount;
use App\Models\DriverProfile;
use App\Models\DriverTier;
use App\Models\PricingSetting;
use App\Models\User;
use App\Services\Driver\DriverProfileUpdater;
use App\Services\DriverVerificationRequirementRegistry;
use App\Services\PlanLimits;
use App\Services\WhatsAppConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lógica real de actualizar/desactivar/reactivar el perfil en
 * App\Services\Driver\DriverProfileUpdater (roadmap app móvil, "full backend").
 */
class DriverProfileController extends Controller
{
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

        $missingDriverRequirements = collect($user->driverProfile?->missingRegistrationInformation() ?? []);
        if ($user->driverProfile?->admin_activated_at === null
            && DriverVerificationRequirementRegistry::isRequired('profile_photo')
            && ! $user->avatar_path) {
            $missingDriverRequirements->push([
                'key' => 'profile_photo',
                'label' => 'foto de perfil',
                'section' => 'verification',
            ]);
        }

        return Inertia::render('Driver/Profile', [
            'driverProfile' => $request->user()->driverProfile,
            // La pantalla de perfil debe explicar exactamente la misma causa
            // que bloquea el switch y el endpoint de ubicación.
            'canConnect' => (bool) $user->driverProfile?->canBecomeAvailable(),
            'connectionBlockReason' => $user->driverProfile?->availabilityBlockReason(),
            'missingDriverRequirements' => $missingDriverRequirements->values()->all(),
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
            // Cargo por distancia de recogida (pedido explícito del usuario):
            // umbral y porcentaje vigentes, para explicarle al conductor qué
            // significa el interruptor de acá abajo (ver PriceCalculator).
            'pickupSurchargeThresholdKm' => (float) PricingSetting::current()->pickup_surcharge_threshold_km,
            'pickupSurchargePercent' => (int) PricingSetting::current()->pickup_surcharge_percent,
            // Billetera cooperativa-conductor (pedido explícito del
            // usuario): el conductor también tiene que poder ver si debe
            // pagarle a la cooperativa o si le deben a él, no solo la
            // cooperativa desde su propio panel (ver
            // Cooperative/DriverShow.vue). El signo es el mismo que
            // CooperativeWalletEntry::balanceFor() — positivo = él debe.
            'cooperativeWallet' => ($cooperative = CooperativeDriverMembership::activeCooperativeFor($user->id)) ? [
                'cooperative_name' => $cooperative->name,
                'balance' => CooperativeWalletEntry::balanceFor($cooperative->id, $user->id),
            ] : null,
            // Cuentas bancarias (pedido explícito del usuario): el propio
            // conductor siempre ve todo completo, a diferencia del cliente
            // durante una carrera (ver RideController::show(), donde la
            // cédula se enmascara).
            'bankAccounts' => $user->bankAccounts()->get(),
            'banks' => DriverBankAccount::banks(),
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
        $this->driverProfileUpdater->deactivate($request->user());

        return redirect()->route('dashboard')->with('status', 'Listo — ahora es cliente. Su perfil de conductor quedó guardado, puede volver a activarlo cuando quiera.');
    }

    /**
     * "Reactivar mi perfil de conductor" (pedido explícito del usuario):
     * atajo de un solo toque para quien pausó antes y quiere volver — no
     * hace falta llenar el formulario de nuevo, los datos siguen ahí.
     */
    public function reactivate(Request $request): RedirectResponse
    {
        $this->driverProfileUpdater->reactivate($request->user());

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
            // Se conserva para no romper el enlace de documentos ya subidos
            // antes de este cambio — ya no se pide ni se exige uno nuevo.
            'police-record' => $user->driverProfile?->police_record_path,
            'vehicle-registration' => $user->driverProfile?->vehicle_registration_path,
            default => null,
        };

        abort_if(blank($path), 404);

        return Storage::disk('local')->response($path);
    }
}
