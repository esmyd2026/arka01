<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pedido explícito del usuario: se elimina completamente el paso de
     * "¿el cliente pagó?" del flujo — la carrera pasa directo de completada a
     * la calificación obligatoria, sin esta confirmación manual de por medio.
     */
    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn(['client_marked_paid', 'driver_marked_paid', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->boolean('client_marked_paid')->default(false);
            $table->boolean('driver_marked_paid')->default(false);
            $table->timestamp('paid_at')->nullable();
        });
    }
};
