<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido explícito del usuario: 30 segundos era muy poco margen real para
 * que un operador de cooperativa alcance a asignar a mano antes de que se
 * active el respaldo automático (RideDispatchAdvancer::startCooperativeDispatch(),
 * acotado siempre a los conductores de esa misma cooperativa). Sube el
 * default a 120s y migra las cooperativas que seguían en el valor por
 * defecto anterior (30) — todavía no hay pantalla para que cada una lo
 * personalice, así que asumir que están en el default es seguro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cooperatives', function (Blueprint $table) {
            $table->unsignedSmallInteger('manual_assignment_timeout_seconds')->default(120)->change();
        });

        DB::table('cooperatives')
            ->where('manual_assignment_timeout_seconds', 30)
            ->update(['manual_assignment_timeout_seconds' => 120]);
    }

    public function down(): void
    {
        Schema::table('cooperatives', function (Blueprint $table) {
            $table->unsignedSmallInteger('manual_assignment_timeout_seconds')->default(30)->change();
        });

        DB::table('cooperatives')
            ->where('manual_assignment_timeout_seconds', 120)
            ->update(['manual_assignment_timeout_seconds' => 30]);
    }
};
