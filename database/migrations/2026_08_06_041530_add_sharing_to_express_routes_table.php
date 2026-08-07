<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pedido explícito del usuario: hoy un conductor no le conviene hacer un
     * Expreso porque va vacío a buscar a una sola persona — si quien lo
     * publica se abre a que otros con ruta/horario parecido se sumen, el
     * precio total se reparte entre más gente y el viaje sí le conviene al
     * conductor. `share_enabled` lo decide el dueño del Expreso al
     * publicarlo (no es automático); `max_companions` es el cupo de
     * acompañantes además de él mismo.
     */
    public function up(): void
    {
        Schema::table('express_routes', function (Blueprint $table) {
            $table->boolean('share_enabled')->default(false)->after('offered_price');
            $table->unsignedTinyInteger('max_companions')->nullable()->after('share_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('express_routes', function (Blueprint $table) {
            $table->dropColumn(['share_enabled', 'max_companions']);
        });
    }
};
