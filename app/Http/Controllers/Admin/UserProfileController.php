<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Controller;
use App\Models\ChatbotMessage;
use App\Models\DriverProfile;
use App\Models\DriverTier;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\Review;
use App\Models\Ride;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Rules\ValidPhoneNumberLocal;
use App\Services\AdminAuditLogger;
use App\Services\PlanLimits;
use App\Services\UserFileCleanup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pedido explícito del usuario: "en el Administrador debe existir una opción
 * para consultar el perfil completo tanto del conductor como del cliente,
 * mostrando toda la información relevante sin necesidad de navegar por
 * diferentes pantallas" — identidad, perfil de conductor (si tiene), flotas
 * como cliente (si tiene), suscripción de cada lado, y su reputación.
 */
class UserProfileController extends Controller
{
    public function __construct(private readonly PlanLimits $planLimits) {}

    public function show(User $user): Response
    {
        $user->load(['driverProfile.verifier', 'driverProfile.activatedBy', 'city']);

        $rating = round((float) $user->reviewsReceived()->avg('rating'), 1);
        $reviewCount = $user->reviewsReceived()->count();

        $recentReviews = Review::query()
            ->where('reviewee_user_id', $user->id)
            ->with(['reviewer', 'ride'])
            ->latest()
            ->limit(10)
            ->get();

        // Pedido explícito del usuario: "cuales son los conductores que
        // tiene ese cliente" — antes "Flotas propias" solo mostraba el
        // nombre de la flota y la CANTIDAD de conductores ("2
        // conductor(es)"), sin decir quiénes son. Mismo shape que
        // $driverClients de abajo (la relación inversa), para que ambas
        // pantallas se lean igual de completas.
        $fleetsOwned = $user->isClient()
            ? Fleet::query()
                ->where('owner_user_id', $user->id)
                ->with('activeMembers.driver.driverProfile')
                ->get()
                ->map(function (Fleet $fleet) use ($user) {
                    return [
                        'id' => $fleet->id,
                        'name' => $fleet->name,
                        'drivers' => $fleet->activeMembers->map(function (FleetMember $member) use ($user) {
                            $driver = $member->driver;
                            $profile = $driver->driverProfile;

                            return [
                                'user_id' => $driver->id,
                                'name' => $driver->name,
                                'avatar_url' => $driver->avatar_url,
                                'phone' => $driver->phone,
                                'vehicle' => trim(($profile?->vehicle_make ?? '').' '.($profile?->vehicle_model ?? '')),
                                'rides_together_count' => Ride::query()
                                    ->where('client_user_id', $user->id)
                                    ->where('driver_user_id', $driver->id)
                                    ->where('status', 'completed')
                                    ->count(),
                                'joined_at' => $member->joined_at,
                            ];
                        })->sortByDesc('joined_at')->values(),
                    ];
                })
            : collect();

        // Pedido explícito del usuario: "cuales son las carreras que a
        // realizado con su detalle" — tanto del lado cliente como conductor
        // (la misma cuenta nunca es las dos cosas a la vez, pero el filtro
        // cubre ambos casos sin necesidad de un if aparte).
        $rideHistory = Ride::query()
            ->where('client_user_id', $user->id)
            ->orWhere('driver_user_id', $user->id)
            ->with(['client', 'driver'])
            ->latest('created_at')
            ->limit(20)
            ->get()
            ->map(function (Ride $ride) use ($user) {
                $isClient = $ride->client_user_id === $user->id;
                $counterpart = $isClient ? $ride->driver : $ride->client;

                return [
                    'id' => $ride->id,
                    'status' => $ride->status,
                    'counterpart_name' => $counterpart?->name,
                    'counterpart_role' => $isClient ? 'Conductor' : 'Cliente',
                    'origin_address' => $ride->origin_address,
                    'destination_address' => $ride->destination_address,
                    'distance_km' => $ride->distance_km !== null ? (float) $ride->distance_km : null,
                    'price' => $ride->price !== null ? (float) $ride->price : null,
                    'started_at' => $ride->started_at?->toIso8601String(),
                    'completed_at' => $ride->completed_at?->toIso8601String(),
                    'cancelled_at' => $ride->cancelled_at?->toIso8601String(),
                    'created_at' => $ride->created_at?->toIso8601String(),
                ];
            });

        // Pedido explícito del usuario: "ver el detalle de los clientes que
        // tiene cada conductor" desde el admin, y poder sacarlo — ver
        // removeDriverClient() más abajo.
        $driverClients = $user->isDriver()
            ? FleetMember::query()
                ->where('driver_user_id', $user->id)
                ->whereNull('left_at')
                ->with('fleet.owner')
                ->get()
                ->map(function (FleetMember $member) use ($user) {
                    $client = $member->fleet->owner;

                    return [
                        'member_id' => $member->id,
                        'client_id' => $client->id,
                        'client_name' => $client->name,
                        'client_avatar_url' => $client->avatar_url,
                        'client_phone' => $client->phone,
                        'fleet_name' => $member->fleet->name,
                        'joined_at' => $member->joined_at,
                        'rides_together_count' => Ride::query()
                            ->where('driver_user_id', $user->id)
                            ->where('client_user_id', $client->id)
                            ->where('status', 'completed')
                            ->count(),
                    ];
                })
                ->sortByDesc('joined_at')
                ->values()
            : collect();

        // Pedido explícito del usuario ("ayudame a ver la trazabilidad de
        // los whatsapp en el perfil de cada usuario") — la misma
        // transcripción completa que ya se registra en ChatbotMessage
        // (entrante en WhatsAppWebhookController::receive(), saliente en
        // los primitivos de WhatsAppFreeformSender), ahora en la ficha de
        // CUALQUIER usuario (cliente, conductor o admin), no solo clientes.
        $whatsappMessages = ChatbotMessage::query()
            ->where('user_id', $user->id)
            ->when($user->phone, fn ($q) => $q->orWhere('phone', $user->phone))
            ->orderBy('created_at')
            ->get(['id', 'direction', 'body', 'meta', 'created_at']);

        return Inertia::render('Admin/UserProfile', [
            // locked_at está en User::$hidden por defecto (auditoría de
            // seguridad: no debe verlo cualquiera) — acá sí hace falta,
            // es la única pantalla donde un admin ve si la cuenta está
            // bloqueada y puede reactivarla.
            'profileUser' => $user->makeVisible('locked_at'),
            'driverPlan' => $user->isDriver() ? $this->planLimits->forDriver($user) : null,
            // Medalla vigente por puntos (pedido explícito del usuario: poder
            // ver y ajustar los puntos desde acá) — ver updatePoints() abajo.
            'driverTier' => $user->driverProfile ? DriverTier::forPoints($user->driverProfile->total_points)->toBadge() : null,
            'clientPlan' => $user->isClient() ? $this->planLimits->forClient($user) : null,
            'fleetsOwned' => $fleetsOwned,
            'driverClients' => $driverClients,
            'rideHistory' => $rideHistory,
            'averageRating' => $rating,
            'reviewCount' => $reviewCount,
            'recentReviews' => $recentReviews,
            'whatsappMessages' => $whatsappMessages,
            'countryCodes' => RegisteredUserController::COUNTRY_CODES,
        ]);
    }

    /**
     * Corrige el correo y/o el teléfono declarados (pedido explícito del
     * usuario: "permiteme actualizar el correo y el telefono") — mismo
     * criterio de unicidad y re-verificación que ya usa
     * DriverProfileController::update() cuando el conductor corrige su
     * propio número, y ProfileController::update() para el correo. Acá lo
     * dispara un admin, para casos de soporte (typo al registrarse, cambió
     * de operadora, etc.) — nunca borra el teléfono, para eso está
     * releasePhone() de abajo, una acción aparte y explícita.
     */
    public function updateContact(Request $request, User $user): RedirectResponse
    {
        $request->merge([
            'phone_local' => filled($request->input('phone_local'))
                ? ValidPhoneNumberLocal::normalize($request->input('country_code'), $request->input('phone_local'))
                : null,
        ]);

        $validated = $request->validate([
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($user->id)],
            'country_code' => ['required_with:phone_local', 'nullable', 'string', Rule::in(RegisteredUserController::COUNTRY_CODES)],
            'phone_local' => ['nullable', 'string', new ValidPhoneNumberLocal],
        ]);

        $newPhone = filled($validated['phone_local'] ?? null)
            ? $validated['country_code'].$validated['phone_local']
            : $user->phone;

        if ($newPhone !== $user->phone && User::query()->where('phone', $newPhone)->where('id', '!=', $user->id)->exists()) {
            throw ValidationException::withMessages([
                'phone_local' => 'Ese número ya está registrado por otra cuenta de Arka01.',
            ]);
        }

        $oldValue = ['email' => $user->email, 'phone' => $user->phone];

        if ($validated['email'] !== $user->email) {
            $user->email = $validated['email'];
            $user->email_verified_at = null;
        }

        if ($newPhone !== $user->phone) {
            $user->phone = $newPhone;
            $user->phone_verified_at = null;
            $user->phone_verification_code = null;
            $user->phone_verification_expires_at = null;
            // Un número que cambia de dueño no puede arrastrar la ventana
            // de 24h del anterior — si no, un mensaje que le llegue al
            // número nuevo se procesaría todavía a nombre de esta cuenta.
            WhatsAppSession::query()->where('user_id', $user->id)->delete();
        }

        $user->save();

        AdminAuditLogger::log(
            adminUserId: $request->user()->id,
            action: 'user.contact.update',
            module: 'usuarios',
            oldValue: ['user_id' => $user->id] + $oldValue,
            newValue: ['user_id' => $user->id, 'email' => $user->email, 'phone' => $user->phone],
        );

        Log::info('Correo/teléfono corregidos a mano por un admin.', [
            'admin_id' => $request->user()->id, 'user_id' => $user->id,
        ]);

        return back()->with('status', 'Contacto actualizado.');
    }

    /**
     * "Dar de baja" un número (pedido explícito del usuario) — lo libera
     * por completo: nadie puede recibir avisos ni pedir carreras con él a
     * nombre de esta cuenta hasta que declare uno nuevo, y queda disponible
     * para que otra cuenta lo registre (`users.phone` es único, pero
     * permite null). Acción aparte de updateContact() a propósito: es más
     * drástica que una simple corrección, así que no debería poder pasar
     * sin querer al editar el correo.
     */
    public function releasePhone(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->phone, 404);

        $oldPhone = $user->phone;

        $user->forceFill([
            'phone' => null,
            'phone_verified_at' => null,
            'phone_verification_code' => null,
            'phone_verification_expires_at' => null,
        ])->save();

        WhatsAppSession::query()->where('user_id', $user->id)->delete();

        AdminAuditLogger::log(
            adminUserId: $request->user()->id,
            action: 'user.phone.release',
            module: 'usuarios',
            oldValue: ['user_id' => $user->id, 'phone' => $oldPhone],
        );

        Log::info('Número dado de baja a mano por un admin.', [
            'admin_id' => $request->user()->id, 'user_id' => $user->id, 'phone' => $oldPhone,
        ]);

        return back()->with('status', 'Número dado de baja — queda libre para otra cuenta.');
    }

    /**
     * Reactiva una cuenta bloqueada (pedido explícito del usuario: el
     * bloqueo desde el aviso de "si no fue usted" es una vía de un solo
     * sentido para el propio usuario — reactivarla queda a propósito como
     * una acción de admin, no algo que se pueda deshacer solo con el mismo
     * link, ver App\Http\Controllers\Auth\SessionTakeoverController::lock()).
     */
    public function unlock(User $user): RedirectResponse
    {
        $user->forceFill(['locked_at' => null])->save();

        Log::info('Cuenta reactivada por un admin.', ['user_id' => $user->id]);

        return back()->with('status', 'Cuenta reactivada.');
    }

    /**
     * Ajuste manual de puntos (pedido explícito del usuario): hoy los puntos
     * de un conductor solo suben solos, uno por carrera completada (ver
     * RideController::complete()) — no había ninguna forma de corregirlos a
     * mano (ej. compensar una carrera coordinada fuera de la app, o un caso
     * puntual). Cambia la medalla vigente al toque (App\Models\DriverTier::forPoints()),
     * así que puede habilitar o quitar el directorio público de inmediato —
     * por eso queda una acción de admin con registro, no un ajuste silencioso.
     */
    public function updatePoints(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->driverProfile, 404);

        $validated = $request->validate([
            'total_points' => ['required', 'integer', 'min:0'],
        ]);

        // Mismo criterio que suspended_at (Admin\DriverController): a
        // propósito NO está en $fillable de DriverProfile — solo se toca
        // desde acá, vía forceFill(), nunca desde un formulario del propio
        // conductor.
        $oldPoints = $user->driverProfile->total_points;
        $user->driverProfile->forceFill(['total_points' => $validated['total_points']])->save();

        AdminAuditLogger::log(
            adminUserId: $request->user()->id,
            action: 'driver.points.update',
            module: 'usuarios',
            oldValue: ['user_id' => $user->id, 'total_points' => $oldPoints],
            newValue: ['user_id' => $user->id, 'total_points' => $validated['total_points']],
        );

        Log::info('Puntos de conductor ajustados a mano por un admin.', [
            'admin_id' => $request->user()->id, 'user_id' => $user->id,
            'de' => $oldPoints, 'a' => $validated['total_points'],
        ]);

        return back()->with('status', 'Puntos actualizados.');
    }

    /**
     * Activación manual de un conductor puntual (pedido explícito del
     * usuario: "permiteme colocar a un conductor activo asi no mande toda
     * la informacion. para que pueda operar. y se pueda poner disponible")
     * — salta el requisito de documentos/seguro completos SOLO para este
     * conductor (ver DriverProfile::hasCompleteRegistrationInformation()),
     * para casos puntuales (cuenta de demo, ya vetado por una cooperativa,
     * etc.). La nota es obligatoria: es un salteo de un requisito de
     * seguridad, tiene que quedar registrado por qué.
     */
    public function forceActivate(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->driverProfile, 404);

        $validated = $request->validate(['note' => ['required', 'string', 'max:500']]);

        $profile = $user->driverProfile;
        $profile->forceFill([
            'admin_activated_at' => now(),
            'admin_activated_by' => $request->user()->id,
            'admin_activation_note' => $validated['note'],
            // Coherente con "que pueda operar": si todavía no estaba
            // aprobado, queda aprobado también — availabilityBlockReason()
            // sigue exigiendo verification_status=approved además del
            // chequeo de información completa.
            'verification_status' => 'approved',
            'verification_rejection_reason' => null,
            'verified_at' => $profile->verified_at ?? now(),
            'verified_by' => $profile->verified_by ?? $request->user()->id,
        ])->save();

        AdminAuditLogger::log(
            adminUserId: $request->user()->id,
            action: 'driver.force_activate',
            module: 'usuarios',
            newValue: ['user_id' => $user->id, 'note' => $validated['note']],
        );

        Log::warning('Conductor activado a mano por un admin, sin exigir información completa.', [
            'admin_id' => $request->user()->id, 'user_id' => $user->id, 'note' => $validated['note'],
        ]);

        return back()->with('status', 'Conductor activado — ya puede operar y ponerse disponible.');
    }

    /**
     * Deshace la activación manual (vuelve a exigir información completa
     * como a cualquier otro conductor) — no se pidió, pero un salteo de un
     * requisito de seguridad necesita poder revertirse sin tener que
     * tocar la base de datos a mano.
     */
    public function revokeForceActivate(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->driverProfile, 404);

        $user->driverProfile->forceFill([
            'admin_activated_at' => null,
            'admin_activated_by' => null,
            'admin_activation_note' => null,
        ])->save();

        AdminAuditLogger::log(
            adminUserId: $request->user()->id,
            action: 'driver.force_activate.revoke',
            module: 'usuarios',
            oldValue: ['user_id' => $user->id],
        );

        Log::info('Activación manual de conductor revocada por un admin.', [
            'admin_id' => $request->user()->id, 'user_id' => $user->id,
        ]);

        return back()->with('status', 'Activación manual revocada — vuelve a exigírsele información completa.');
    }

    /**
     * Pedido explícito del usuario: "como hago para pasar yo a un cliente
     * como conductor" — crea el perfil de conductor directo desde el
     * panel, sin que la persona tenga que registrarse de nuevo ni
     * completar vehículo/documentos ella misma (los puede cargar después
     * desde su propio perfil). Ya queda activado y aprobado, mismo
     * criterio y misma nota obligatoria que forceActivate() de arriba —
     * salta el mismo requisito de seguridad, solo que de entrada.
     */
    public function convertToDriver(Request $request, User $user): RedirectResponse
    {
        abort_if($user->driverProfile, 409);
        abort_if($user->isCooperative(), 403);

        $validated = $request->validate(['note' => ['required', 'string', 'max:500']]);

        // admin_activated_at/by/note no son mass-assignable a propósito
        // (mismo criterio que forceActivate() de arriba) — se completan
        // con forceFill() después de crear la fila con lo mínimo.
        $profile = DriverProfile::query()->create([
            'user_id' => $user->id,
            'driver_type' => 'independent',
            'verification_status' => 'approved',
            'verified_at' => now(),
            'verified_by' => $request->user()->id,
        ]);
        $profile->forceFill([
            'admin_activated_at' => now(),
            'admin_activated_by' => $request->user()->id,
            'admin_activation_note' => $validated['note'],
        ])->save();

        AdminAuditLogger::log(
            adminUserId: $request->user()->id,
            action: 'driver.convert_from_client',
            module: 'usuarios',
            newValue: ['user_id' => $user->id, 'driver_profile_id' => $profile->id, 'note' => $validated['note']],
        );

        Log::warning('Cliente convertido en conductor a mano por un admin.', [
            'admin_id' => $request->user()->id, 'user_id' => $user->id, 'note' => $validated['note'],
        ]);

        return back()->with('status', 'Convertido en conductor — ya puede operar y ponerse disponible.');
    }

    /**
     * Saca a un cliente de la flota de un conductor (pedido explícito del
     * usuario: "que pueda eliminarle") — mismo mecanismo que ya usa el
     * propio cliente para sacar a un conductor (FleetMemberController::destroy()),
     * disparado acá por un admin en vez del dueño de la flota. No se borra
     * la fila: se cierra con `left_at` para conservar el historial de la
     * relación (trazabilidad, sección 9.6).
     */
    public function removeDriverClient(Request $request, User $user, FleetMember $member): RedirectResponse
    {
        // El `{member}` tiene que ser de verdad una flota de ESTE conductor
        // — sin este chequeo, alguien podría mandar el id de un miembro de
        // otro conductor por la URL y sacarlo por error.
        abort_unless($member->driver_user_id === $user->id && $member->isActive(), 404);

        $clientUserId = $member->fleet->owner_user_id;

        $member->update([
            'left_at' => now(),
            'left_reason' => 'admin_removed',
            'removed_by' => $request->user()->id,
        ]);

        AdminAuditLogger::log(
            adminUserId: $request->user()->id,
            action: 'driver.client.remove',
            module: 'usuarios',
            oldValue: ['fleet_member_id' => $member->id, 'driver_user_id' => $user->id, 'client_user_id' => $clientUserId],
        );

        Log::info('Cliente sacado de la flota de un conductor por un admin.', [
            'admin_id' => $request->user()->id,
            'driver_user_id' => $user->id,
            'client_user_id' => $clientUserId,
        ]);

        return back()->with('status', 'Se sacó a ese cliente de la flota del conductor.');
    }

    /**
     * Elimina una cuenta real y todo lo que le pertenece (pedido explícito
     * del usuario): archivos en disco (avatar, licencia/vehículo, fotos de
     * Viajes en VAN, comprobantes de pago — ver UserFileCleanup) y, por el
     * cascade que ya tienen las FKs a `users.id` en todas las migraciones,
     * su historial de carreras, flotas/membresías, reseñas recibidas y
     * hechas, suscripciones, tickets de soporte, sesiones de WhatsApp, etc.
     * Es irreversible, así que además del diálogo de confirmación del
     * navegador se exige escribir el correo exacto de la cuenta — nunca hay
     * que confiar solo en una confirmación del lado del cliente para una
     * acción destructiva de este tamaño.
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        // Nunca se borra una cuenta admin desde acá (mismo criterio que
        // Admin\SystemController::resetDemo()) — cubre de paso que un admin
        // se borre a sí mismo, porque su propia cuenta también es admin.
        if ($user->is_admin) {
            abort(403, 'No se pueden eliminar cuentas de administrador desde acá.');
        }

        $request->validate(['confirm_email' => ['required', 'string']]);

        if (mb_strtolower(trim($request->string('confirm_email'))) !== mb_strtolower($user->email)) {
            throw ValidationException::withMessages([
                'confirm_email' => 'El correo escrito no coincide con el de esta cuenta.',
            ]);
        }

        $summary = ['name' => $user->name, 'email' => $user->email, 'role' => $user->role];
        $redirectRoute = $user->isDriver() ? 'admin.drivers.index' : 'admin.clients.index';

        DB::transaction(function () use ($user) {
            UserFileCleanup::purge($user);
            $user->delete();
        });

        AdminAuditLogger::log(
            adminUserId: $request->user()->id,
            action: 'user.delete',
            module: 'usuarios',
            oldValue: $summary,
        );

        Log::warning('Cuenta eliminada por un admin.', ['admin_id' => $request->user()->id] + $summary);

        return redirect()->route($redirectRoute)
            ->with('status', "Se eliminó la cuenta de {$summary['name']} y todo su historial.");
    }
}
