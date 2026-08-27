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
            // Bug reportado por el usuario: una carrera PROGRAMADA
            // (status='scheduled') cuya hora ya pasó se quedaba mostrando
            // "Iniciar viaje" para siempre, sin ningún aviso — nada
            // detectaba que ya se venció. Mismo criterio que
            // driver_reminder_sent_at (el aviso de ANTES de la hora): esta
            // bandera evita mandar el aviso de "ya venció" más de una vez.
            $table->timestamp('overdue_alert_sent_at')->nullable()->after('driver_reminder_sent_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn('overdue_alert_sent_at');
        });
    }
};
