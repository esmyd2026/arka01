<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // El catálogo de planes ya no se siembra acá: vive en la propia
        // migración de subscription_plans (así existe siempre, incluso en
        // bases nuevas donde todavía no se corrió ningún seeder) y de ahí en
        // más se administra por completo desde /admin/planes.
        $this->call(DemoDataSeeder::class);
    }
}
