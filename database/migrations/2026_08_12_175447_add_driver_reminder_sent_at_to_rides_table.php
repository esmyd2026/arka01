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
            // Pedido explícito del usuario: avisarle al conductor 15-20 min
            // antes de una carrera programada — bandera de "ya se avisó" para
            // que el barrido periódico (App\Console\Commands\SendUpcomingRideReminders)
            // no lo repita en cada corrida.
            $table->timestamp('driver_reminder_sent_at')->nullable()->after('pending_reschedule_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn('driver_reminder_sent_at');
        });
    }
};
