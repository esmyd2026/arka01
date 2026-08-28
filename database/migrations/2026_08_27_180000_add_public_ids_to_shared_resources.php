<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('public_id')->nullable()->unique()->after('id');
        });

        Schema::table('cooperatives', function (Blueprint $table) {
            $table->uuid('public_id')->nullable()->unique()->after('id');
        });

        Schema::table('rides', function (Blueprint $table) {
            $table->uuid('public_id')->nullable()->unique()->after('id');
        });

        // Los modelos generan el UUID para registros nuevos. Este barrido
        // cubre los existentes sin cambiar sus llaves primarias ni relaciones.
        foreach (['users', 'cooperatives', 'rides'] as $table) {
            DB::table($table)
                ->whereNull('public_id')
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($table) {
                    foreach ($rows as $row) {
                        DB::table($table)->where('id', $row->id)->update([
                            'public_id' => (string) Str::uuid(),
                        ]);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::table('rides', fn (Blueprint $table) => $table->dropColumn('public_id'));
        Schema::table('cooperatives', fn (Blueprint $table) => $table->dropColumn('public_id'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('public_id'));
    }
};
