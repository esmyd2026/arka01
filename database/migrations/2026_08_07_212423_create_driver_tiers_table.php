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
        // driver_tiers: medallas del conductor (pedido explícito del usuario:
        // fidelizar el uso de la app en vez de arreglar directo por WhatsApp
        // — subir de medalla depende de carreras COMPLETADAS por la app, no
        // de la calificación). Reemplaza el criterio fijo de
        // App\Services\DriverCategory::forRating() para el lado conductor —
        // acá es un catálogo editable desde /admin/medallas, no una regla en
        // código, mismo espíritu que subscription_plans.
        Schema::create('driver_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // A partir de cuántos puntos aplica esta medalla. La medalla
            // vigente de un conductor es la de mayor min_points que no
            // supere sus puntos actuales — sin un campo max_points aparte,
            // para que el admin no tenga que mantener sincronizados un
            // mínimo y un máximo a mano (evita huecos o superposiciones).
            $table->unsignedInteger('min_points')->unique();

            $table->string('badge_emoji')->nullable();

            // Uno de un set fijo de colores (ver
            // resources/js/Utils/tierBadge.js) — nunca una clase de Tailwind
            // libre: el build de Tailwind solo genera CSS para clases que
            // aparecen literalmente en el código fuente, una guardada en la
            // base no serviría de nada.
            $table->string('color_key')->default('slate');

            // Solo estas medallas aparecen en el directorio público
            // (DriverDirectoryController::index) — se suma al filtro de
            // is_public que ya depende del plan pagado: hace falta plan Y
            // medalla ganada con actividad real.
            $table->boolean('is_public_eligible')->default(false);

            $table->timestamps();
        });

        // Semilla inicial (pedido explícito del usuario, con sus propios
        // números de ejemplo) — mismos emojis/colores que ya usaba
        // DriverCategory::forRating() para que el cambio visual del primer
        // día sea mínimo. Editable desde /admin/medallas sin tocar código.
        $now = now();

        DB::table('driver_tiers')->insert([
            ['name' => 'Cobre', 'min_points' => 0, 'badge_emoji' => '🥉', 'color_key' => 'orange', 'is_public_eligible' => false, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Plata', 'min_points' => 150, 'badge_emoji' => '🥈', 'color_key' => 'slate', 'is_public_eligible' => false, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Oro', 'min_points' => 500, 'badge_emoji' => '🥇', 'color_key' => 'yellow', 'is_public_eligible' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Diamante', 'min_points' => 1000, 'badge_emoji' => '💎', 'color_key' => 'cyan', 'is_public_eligible' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('driver_tiers');
    }
};
