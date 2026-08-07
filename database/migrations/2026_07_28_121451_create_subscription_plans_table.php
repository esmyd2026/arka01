<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // subscription_plans: el catálogo de planes de la Fase 5 (secciones 7.2
        // y 7.3 del alcance). Reemplaza a las constantes de config/arka.php que
        // se usaron como plan Gratis fijo desde la Fase 1 — a partir de acá el
        // límite de cada usuario sale de su suscripción activa (o de la fila
        // "gratis" de esta tabla si nunca contrató nada), a través de
        // app/Services/PlanLimits.php.
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();

            // 'driver' o 'client': son dos escaleras de planes separadas
            // (sección 7.1 — la flota es del cliente, no del conductor).
            $table->string('owner_type');

            // Slug corto y estable (ej. "gratis", "plus", "institucional") para
            // poder referenciar un plan desde código sin depender del nombre
            // visible, que puede cambiar.
            $table->string('code');
            $table->string('name');
            $table->decimal('monthly_price', 8, 2)->default(0);

            // --- Límites del lado conductor (sección 7.2) ---
            $table->unsignedInteger('max_clients')->nullable();
            $table->boolean('public_visibility')->default(false);
            $table->boolean('priority_listing')->default(false);
            $table->boolean('verified_badge')->default(false);

            // --- Límites del lado cliente (sección 7.3) ---
            $table->unsignedInteger('max_fleets')->nullable();
            $table->unsignedInteger('max_drivers_per_fleet')->nullable();

            // Un plan inactivo desaparece del catálogo de "Mi plan" y del
            // selector de activación del admin, pero los usuarios que ya lo
            // tenían contratado lo siguen usando tal cual (no se les cambia
            // nada de golpe). Editable desde /admin/planes, nunca por código.
            $table->boolean('is_active')->default(true);

            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['owner_type', 'code']);
        });

        // Catálogo inicial (secciones 7.2 y 7.3): esto es el punto de partida
        // del negocio, no una regla fija en el código — desde /admin/planes
        // un admin puede editar precios y cupos, o crear/desactivar planes,
        // sin volver a tocar ni desplegar código nunca más.
        $now = now();

        DB::table('subscription_plans')->insert([
            ['owner_type' => 'driver', 'code' => 'gratis', 'name' => 'Gratis', 'monthly_price' => 0, 'max_clients' => 3, 'public_visibility' => false, 'priority_listing' => false, 'verified_badge' => false, 'max_fleets' => null, 'max_drivers_per_fleet' => null, 'is_active' => true, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['owner_type' => 'driver', 'code' => 'basico', 'name' => 'Básico', 'monthly_price' => 10, 'max_clients' => 25, 'public_visibility' => false, 'priority_listing' => false, 'verified_badge' => false, 'max_fleets' => null, 'max_drivers_per_fleet' => null, 'is_active' => true, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['owner_type' => 'driver', 'code' => 'plus', 'name' => 'Plus', 'monthly_price' => 15, 'max_clients' => 45, 'public_visibility' => true, 'priority_listing' => false, 'verified_badge' => false, 'max_fleets' => null, 'max_drivers_per_fleet' => null, 'is_active' => true, 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['owner_type' => 'driver', 'code' => 'pro', 'name' => 'Pro', 'monthly_price' => 25, 'max_clients' => 80, 'public_visibility' => true, 'priority_listing' => true, 'verified_badge' => true, 'max_fleets' => null, 'max_drivers_per_fleet' => null, 'is_active' => true, 'sort_order' => 4, 'created_at' => $now, 'updated_at' => $now],
            // max_clients null = "a definir por convenio" (custom_max_clients de la suscripción, sección 7.2).
            ['owner_type' => 'driver', 'code' => 'institucional', 'name' => 'Institucional', 'monthly_price' => 40, 'max_clients' => null, 'public_visibility' => true, 'priority_listing' => true, 'verified_badge' => true, 'max_fleets' => null, 'max_drivers_per_fleet' => null, 'is_active' => true, 'sort_order' => 5, 'created_at' => $now, 'updated_at' => $now],

            ['owner_type' => 'client', 'code' => 'gratis', 'name' => 'Gratis', 'monthly_price' => 0, 'max_clients' => null, 'public_visibility' => false, 'priority_listing' => false, 'verified_badge' => false, 'max_fleets' => 1, 'max_drivers_per_fleet' => 20, 'is_active' => true, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['owner_type' => 'client', 'code' => 'plus', 'name' => 'Plus', 'monthly_price' => 5, 'max_clients' => null, 'public_visibility' => false, 'priority_listing' => false, 'verified_badge' => false, 'max_fleets' => 1, 'max_drivers_per_fleet' => 50, 'is_active' => true, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['owner_type' => 'client', 'code' => 'multiflota', 'name' => 'Multi-flota', 'monthly_price' => 12, 'max_clients' => null, 'public_visibility' => false, 'priority_listing' => false, 'verified_badge' => false, 'max_fleets' => 5, 'max_drivers_per_fleet' => 50, 'is_active' => true, 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
