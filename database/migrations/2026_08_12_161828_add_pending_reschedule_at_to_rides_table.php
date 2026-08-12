<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            // Pedido explícito del usuario: poder editar una carrera
            // programada si el cliente se equivocó de fecha/hora — el cambio
            // no se aplica solo, queda acá hasta que el conductor lo
            // confirme (mismo criterio que la negociación de precio: nadie
            // queda comprometido a un cambio que no aceptó). Nulo = sin
            // ningún cambio de horario pendiente.
            $table->timestamp('pending_reschedule_at')->nullable()->after('cancelled_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn('pending_reschedule_at');
        });
    }
};
