<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Incluye a las cooperativas en el tipo de bolsa de despacho.
 *
 * El módulo de cooperativas guarda `dispatch_pool = cooperative` para que la
 * cascada de conductores siga usando RideDispatchAdvancer. En SQLite el enum
 * se representa como texto y el error no aparece durante los tests, pero
 * MySQL sí restringe los valores del ENUM y rechazaba la solicitud real.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            "ALTER TABLE ride_requests MODIFY dispatch_pool ENUM('fleet', 'public', 'both', 'cooperative') NULL"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // El enum anterior no puede restaurarse mientras existan solicitudes
        // cooperativas con ese valor. Se conserva la solicitud y solo se
        // elimina el indicador de cascada al revertir esta migración.
        DB::table('ride_requests')
            ->where('dispatch_pool', 'cooperative')
            ->update(['dispatch_pool' => null]);

        DB::statement(
            "ALTER TABLE ride_requests MODIFY dispatch_pool ENUM('fleet', 'public', 'both') NULL"
        );
    }
};
