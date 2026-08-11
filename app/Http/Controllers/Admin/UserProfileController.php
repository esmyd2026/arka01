<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DriverTier;
use App\Models\Fleet;
use App\Models\Review;
use App\Models\User;
use App\Services\AdminAuditLogger;
use App\Services\PlanLimits;
use App\Services\UserFileCleanup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        $user->load(['driverProfile.verifier', 'city']);

        $rating = round((float) $user->reviewsReceived()->avg('rating'), 1);
        $reviewCount = $user->reviewsReceived()->count();

        $recentReviews = Review::query()
            ->where('reviewee_user_id', $user->id)
            ->with(['reviewer', 'ride'])
            ->latest()
            ->limit(10)
            ->get();

        $fleetsOwned = $user->isClient()
            ? Fleet::query()
                ->where('owner_user_id', $user->id)
                ->withCount(['activeMembers as active_members_count'])
                ->get()
            : collect();

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
            'averageRating' => $rating,
            'reviewCount' => $reviewCount,
            'recentReviews' => $recentReviews,
        ]);
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
