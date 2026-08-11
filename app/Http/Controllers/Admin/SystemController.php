<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserFileCleanup;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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
        ]);
    }

    public function resetDemo(): RedirectResponse
    {
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

            User::query()
                ->whereIn('id', $demoSubscribers->pluck('id'))
                ->delete();

            // El seeder ya no intenta recrear el admin si uno ya existe (ver
            // DemoDataSeeder) — acá solo repone los 4 clientes + 4 conductores
            // demo que se acaban de borrar.
            Artisan::call('db:seed', ['--class' => DemoDataSeeder::class, '--force' => true]);
        });

        return back()->with('status', 'Suscriptores de prueba reiniciados. Las configuraciones y su cuenta admin quedaron intactas.');
    }
}
