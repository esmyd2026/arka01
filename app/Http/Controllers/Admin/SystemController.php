<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionRequest;
use App\Models\User;
use App\Models\VanTrip;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
            $demoSubscribers->each(fn (User $user) => $this->deleteFilesFor($user));

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

    /**
     * Fotos y comprobantes en disco de un suscriptor demo puntual — nada de
     * esto lo borra el cascade de la base, solo las filas.
     */
    private function deleteFilesFor(User $user): void
    {
        // Foto de perfil: puede ser una URL externa (login con Google), ahí
        // no hay nada que borrar del disco — mismo criterio que ProfileController.
        if ($user->avatar_path && ! str_starts_with($user->avatar_path, 'http')) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        if ($user->driverProfile) {
            if ($user->driverProfile->license_photo_path) {
                Storage::disk('local')->delete($user->driverProfile->license_photo_path);
            }
            if ($user->driverProfile->vehicle_photo_path) {
                Storage::disk('public')->delete($user->driverProfile->vehicle_photo_path);
            }

            // Fotos de sus Viajes en VAN publicados, si publicó alguno.
            VanTrip::query()
                ->where('driver_user_id', $user->id)
                ->with('photos')
                ->get()
                ->each(function (VanTrip $trip) {
                    $trip->photos->each(fn ($photo) => Storage::disk('public')->delete($photo->photo_path));
                });
        }

        // Comprobantes de pago subidos (pedido explícito del usuario:
        // "transacciones") — disco privado, ver SubscriptionRequestController.
        SubscriptionRequest::query()
            ->where('user_id', $user->id)
            ->whereNotNull('payment_proof_path')
            ->get()
            ->each(fn (SubscriptionRequest $request) => Storage::disk('local')->delete($request->payment_proof_path));
    }
}
