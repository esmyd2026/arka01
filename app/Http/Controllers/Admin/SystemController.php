<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Zona de peligro" del panel admin (pedido explícito del usuario): borrar
 * toda la data de prueba acumulada y dejar el sistema listo de nuevo, sin
 * tocar ninguna cuenta real. Alcance confirmado con el usuario: borra SOLO
 * las cuentas @arka01.test (el dominio que usan tanto DemoDataSeeder como
 * demo:seed-many-drivers) — cualquier otro correo queda intacto.
 */
class SystemController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/System', [
            'demoAccountsCount' => User::query()->where('email', 'like', '%@arka01.test')->count(),
        ]);
    }

    public function resetDemo(): RedirectResponse
    {
        // admin@arka01.test es casi con toda seguridad la cuenta con la que
        // se está usando el panel ahora mismo — hay que chequear ANTES de
        // borrar, porque después de esto el registro ya no existe.
        $wipesOwnAccount = str_ends_with(Auth::user()->email, '@arka01.test');

        DB::transaction(function () {
            // Cascade ya definido en las FKs (flotas, carreras, suscripciones,
            // reseñas, perfiles de conductor, etc.) se encarga del resto.
            User::query()->where('email', 'like', '%@arka01.test')->delete();

            Artisan::call('db:seed', ['--class' => DemoDataSeeder::class, '--force' => true]);
        });

        if ($wipesOwnAccount) {
            Auth::guard('web')->logout();

            request()->session()->invalidate();
            request()->session()->regenerateToken();

            return redirect()->route('login')->with('status', 'Base de demo reiniciada. Inicie sesión de nuevo.');
        }

        return back()->with('status', 'Base de demo reiniciada.');
    }
}
