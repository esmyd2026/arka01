<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\DriverVerificationRequirementRegistry;
use App\Services\NotificationSoundRegistry;
use App\Services\QuickLinkRegistry;
use App\Services\UserFileCleanup;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Zona de peligro" del panel admin (pedido explícito del usuario): borrar
 * la data de prueba acumulada (suscriptores demo, sus carreras, reseñas,
 * suscripciones/comprobantes y fotos) y dejar el sistema listo de nuevo.
 *
 * Alcance confirmado con el usuario (ajuste explícito a la versión
 * anterior, que sí borraba y volvía a crear la cuenta admin de prueba):
 * NUNCA se toca una cuenta con `is_admin = true`, ni las configuraciones ya
 * hechas (planes, tarifas, cupones, banners, etc. — esas tablas ni siquiera
 * las toca este controlador). Solo caen las cuentas @arka01.test que NO son
 * admin — cualquier otro correo, y cualquier admin, quedan intactos.
 */
class SystemController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/System', [
            'demoAccountsCount' => User::query()
                ->where('email', 'like', '%@arka01.test')
                ->where('is_admin', false)
                ->count(),
            // Pedido explícito del usuario: "permiteme en el modulo de
            // sistema de habilitar o no estas opciones del menu tanto las
            // del conductor como las del cliente".
            'quickLinks' => QuickLinkRegistry::withState(SiteSetting::current()->disabled_quick_links ?? []),
            // Pedido explícito del usuario: "permiteme desde el admin poder
            // activar o no lo obligatorio para que el conductor se le haga
            // mas facil activarse".
            'driverRequirements' => DriverVerificationRequirementRegistry::withState(SiteSetting::current()->disabled_driver_requirements ?? []),
            // Pedido explícito del usuario: "una lista de sonidos que pueda
            // seleccionar para las notificaciones... desde el panel
            // administrativo. y que tenga todo el volumen".
            'notificationSounds' => NotificationSoundRegistry::withState(SiteSetting::current()->notification_sounds ?? []),
            'notificationSoundOptions' => NotificationSoundRegistry::soundOptions(),
            'notificationVolume' => SiteSetting::current()->notification_volume ?? 100,
        ]);
    }

    /**
     * Prende/apaga accesos rápidos del menú (conductor y cliente) — ver
     * App\Services\QuickLinkRegistry (única fuente de verdad de qué rutas
     * son apagables) y HandleInertiaRequests::share() (quien de verdad
     * filtra `quickLinks` con esto, en AuthenticatedLayout.vue).
     */
    public function updateQuickLinks(Request $request): RedirectResponse
    {
        $validRoutes = array_keys(QuickLinkRegistry::ITEMS);

        $validated = $request->validate([
            'disabled' => ['array'],
            'disabled.*' => ['string', Rule::in($validRoutes)],
        ]);

        SiteSetting::current()->update([
            'disabled_quick_links' => array_values($validated['disabled'] ?? []),
        ]);

        return back()->with('status', 'Accesos rápidos del menú actualizados.');
    }

    /**
     * Prende/apaga qué le exige el registro/verificación a un conductor
     * (pedido explícito del usuario) — ver
     * App\Services\DriverVerificationRequirementRegistry.
     */
    public function updateDriverRequirements(Request $request): RedirectResponse
    {
        $validKeys = array_keys(DriverVerificationRequirementRegistry::ITEMS);

        $validated = $request->validate([
            'disabled' => ['array'],
            'disabled.*' => ['string', Rule::in($validKeys)],
        ]);

        SiteSetting::current()->update([
            'disabled_driver_requirements' => array_values($validated['disabled'] ?? []),
        ]);

        return back()->with('status', 'Requisitos de conductor actualizados.');
    }

    /**
     * Qué sonido usa cada categoría de aviso + volumen maestro (pedido
     * explícito del usuario) — ver App\Services\NotificationSoundRegistry
     * (categorías/sonidos válidos) y resources/js/Utils/liveAlert.js (dónde
     * de verdad se sintetiza y reproduce cada uno).
     */
    public function updateNotificationSounds(Request $request): RedirectResponse
    {
        $validSounds = array_keys(NotificationSoundRegistry::SOUNDS);
        $validCategories = array_keys(NotificationSoundRegistry::CATEGORIES);

        $validated = $request->validate([
            'sounds' => ['array'],
            'sounds.*' => ['string', Rule::in($validSounds)],
            'volume' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        // Cualquier clave que no sea una categoría real (payload viejo,
        // manipulado a mano) se descarta acá, no confiamos en el frontend.
        $sounds = collect($validated['sounds'] ?? [])->only($validCategories)->all();

        SiteSetting::current()->update([
            'notification_sounds' => $sounds,
            'notification_volume' => $validated['volume'],
        ]);

        return back()->with('status', 'Sonidos de notificaciones actualizados.');
    }

    public function resetDemo(Request $request): RedirectResponse
    {
        $enterDemo = $request->boolean('enter_demo');

        DB::transaction(function () {
            // Nunca se toca una cuenta admin (pedido explícito del usuario) —
            // ni la de quien está usando el panel ahora mismo, ni ninguna
            // otra. Solo conductores y clientes de prueba.
            $demoSubscribers = User::query()
                ->where('email', 'like', '%@arka01.test')
                ->where('is_admin', false)
                ->get();

            // El cascade de las FKs se encarga de las filas (flotas, carreras,
            // reseñas, suscripciones, mensajes, etc.) — pero nunca de los
            // archivos en disco, esos hay que borrarlos a mano ANTES de que
            // el cascade se lleve la fila que guarda la ruta.
            $demoSubscribers->each(fn (User $user) => UserFileCleanup::purge($user));

            // Elimina también las sesiones antiguas de esas cuentas. Aunque
            // luego se vuelvan a crear con otros IDs, ningún navegador debe
            // conservar una sesión que apunte a un usuario demo eliminado.
            DB::table('sessions')
                ->whereIn('user_id', $demoSubscribers->pluck('id'))
                ->delete();

            User::query()
                ->whereIn('id', $demoSubscribers->pluck('id'))
                ->delete();

            // El seeder ya no intenta recrear el admin si uno ya existe (ver
            // DemoDataSeeder) — acá solo repone los 4 clientes + 4 conductores
            // demo que se acaban de borrar.
            Artisan::call('db:seed', ['--class' => DemoDataSeeder::class, '--force' => true]);
        });

        if ($enterDemo) {
            // Desde el botón visual, reiniciar es el paso previo a entrar
            // como cliente, conductor o cooperativa. Cierra únicamente esta
            // sesión admin para que /login no la redirija al área privada.
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', 'Demo reiniciada. Ya puede ingresar con cualquiera de las cuentas de prueba.');
        }

        return back()->with('status', 'Suscriptores de prueba reiniciados. Las configuraciones y su cuenta admin quedaron intactas.');
    }
}
