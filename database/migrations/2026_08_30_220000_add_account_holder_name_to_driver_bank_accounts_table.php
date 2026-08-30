<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_bank_accounts', function (Blueprint $table) {
            // Nullable para poder desplegar sobre cuentas existentes sin
            // bloquear la migración; el formulario lo exige para altas
            // nuevas y las filas actuales se completan abajo con su dueño.
            $table->string('account_holder_name', 120)->nullable()->after('user_id');
        });

        // UPDATE ... JOIN no es portable a SQLite (motor de los tests, ver
        // Admin\OperationsController para el mismo criterio) — se resuelve
        // por usuario en PHP en vez de un solo UPDATE con join.
        DB::table('driver_bank_accounts')
            ->whereNull('account_holder_name')
            ->get(['id', 'user_id'])
            ->groupBy('user_id')
            ->each(function ($accounts, $userId) {
                $name = DB::table('users')->where('id', $userId)->value('name');
                if ($name === null) {
                    return;
                }

                DB::table('driver_bank_accounts')
                    ->whereIn('id', $accounts->pluck('id'))
                    ->update(['account_holder_name' => $name]);
            });
    }

    public function down(): void
    {
        Schema::table('driver_bank_accounts', function (Blueprint $table) {
            $table->dropColumn('account_holder_name');
        });
    }
};
