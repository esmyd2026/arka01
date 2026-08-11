<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    /**
     * Elenco mínimo para probar la app con una base limpia: un admin y
     * exactamente 4 conductores + 4 clientes (pedido explícito del usuario
     * de resetear todo lo acumulado en pruebas manuales anteriores — sin
     * flotas, carreras, suscripciones, reseñas ni Expresos precargados, para
     * que esos flujos se prueben desde cero de verdad). Todas las cuentas
     * usan la contraseña "password".
     */
    public function run(): void
    {
        $password = Hash::make('password');
        $quitoId = City::query()->where('name', 'Quito')->value('id');

        // Pedido explícito del usuario: "reiniciar demo" (Admin\SystemController)
        // ya no borra ninguna cuenta admin — si esta ya existe (de una corrida
        // anterior, o porque el usuario la usa de verdad), no se vuelve a
        // crear. `firstOrCreate` evita el error de correo/teléfono duplicado
        // que tiraría un `create()` sin este chequeo.
        User::query()->firstOrCreate(
            ['email' => 'admin@arka01.test'],
            [
                'name' => 'Admin Arka',
                'phone' => '0990000000',
                'password' => $password,
                'is_admin' => true,
            ]
        );

        // --- Clientes: cuentas puras, sin flota armada todavía (se crea sola
        //     la primera vez que entran a "Mi flota" o al directorio). ---
        $clientes = [
            ['name' => 'Demo Cliente', 'email' => 'cliente@arka01.test', 'phone' => '0990000001'],
            ['name' => 'Otro Cliente', 'email' => 'otro@arka01.test', 'phone' => '0990000002'],
            ['name' => 'Laura Cliente', 'email' => 'laura@arka01.test', 'phone' => '0990000003'],
            ['name' => 'Jorge Cliente', 'email' => 'jorge@arka01.test', 'phone' => '0990000004'],
        ];

        foreach ($clientes as $datos) {
            User::factory()->create([
                'name' => $datos['name'],
                'email' => $datos['email'],
                'phone' => $datos['phone'],
                'password' => $password,
                'city_id' => $quitoId,
            ]);
        }

        // --- Conductores: con perfil ya activado y ubicación en Quito (lo
        //     mínimo para que el mapa y "Pedir carrera" tengan algo que
        //     mostrar sin depender de la geolocalización del navegador). Los
        //     dos primeros con visibilidad pública, para que el directorio
        //     tampoco arranque vacío. ---
        $conductores = [
            ['name' => 'Pedro Chofer', 'email' => 'pedro@arka01.test', 'phone' => '0990000005', 'rate' => 0.45, 'lat' => -0.1856, 'lng' => -78.4708, 'public' => true],
            ['name' => 'Ana Ruedas', 'email' => 'ana@arka01.test', 'phone' => '0990000006', 'rate' => 0.50, 'lat' => -0.1750, 'lng' => -78.4820, 'public' => true],
            ['name' => 'Luis Manejo', 'email' => 'luis@arka01.test', 'phone' => '0990000007', 'rate' => 0.40, 'lat' => -0.2050, 'lng' => -78.5100, 'public' => false],
            ['name' => 'Marta Volante', 'email' => 'marta@arka01.test', 'phone' => '0990000008', 'rate' => 0.55, 'lat' => -0.1650, 'lng' => -78.4600, 'public' => false],
        ];

        foreach ($conductores as $datos) {
            $driver = User::factory()->create([
                'name' => $datos['name'],
                'email' => $datos['email'],
                'phone' => $datos['phone'],
                'password' => $password,
                'city_id' => $quitoId,
            ]);

            DriverProfile::factory()->for($driver)->create([
                'rate_per_km' => $datos['rate'],
                'is_available' => true,
                'is_public' => $datos['public'],
                'current_lat' => $datos['lat'],
                'current_lng' => $datos['lng'],
                'location_updated_at' => now(),
            ]);
        }

        $this->command->info('Base reseteada. Todas las cuentas usan la contraseña "password":');
        $this->command->table(['Rol', 'Email'], [
            ['Admin', 'admin@arka01.test'],
            ['Cliente', 'cliente@arka01.test'],
            ['Cliente', 'otro@arka01.test'],
            ['Cliente', 'laura@arka01.test'],
            ['Cliente', 'jorge@arka01.test'],
            ['Conductor (público)', 'pedro@arka01.test'],
            ['Conductor (público)', 'ana@arka01.test'],
            ['Conductor', 'luis@arka01.test'],
            ['Conductor', 'marta@arka01.test'],
        ]);
    }
}
