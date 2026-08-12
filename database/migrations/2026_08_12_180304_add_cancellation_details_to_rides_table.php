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
            // Pedido explícito del usuario: que el conductor también pueda
            // cancelar (antes solo el cliente), pidiendo un motivo — y una
            // observación libre, opcional. `cancelled_by` en texto libre
            // (no enum de BD), mismo criterio ya usado en el proyecto para
            // columnas de estado que conviene poder extender sin migración
            // (ver ride_requests.status).
            $table->string('cancelled_by')->nullable()->after('cancelled_at');
            $table->string('cancellation_reason')->nullable()->after('cancelled_by');
            $table->text('cancellation_note')->nullable()->after('cancellation_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn(['cancelled_by', 'cancellation_reason', 'cancellation_note']);
        });
    }
};
