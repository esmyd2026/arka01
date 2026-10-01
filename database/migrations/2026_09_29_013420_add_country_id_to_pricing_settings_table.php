<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bug reportado por el usuario: pricing_settings era una fila global
     * única, así que el tope de "tarifa mínima" que un admin configuraba en
     * dólares (ej. 3) le rompía el registro a un conductor en Chile, que
     * necesita declarar miles de pesos. Ahora es una fila POR PAÍS — el
     * backfill deja la fila que ya existía asignada a Ecuador (por
     * iso_code, no por id fijo, para no asumir que siempre es la fila 1).
     */
    public function up(): void
    {
        Schema::table('pricing_settings', function (Blueprint $table) {
            $table->foreignId('country_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        $ecuadorId = DB::table('countries')->where('iso_code', 'EC')->value('id');
        DB::table('pricing_settings')->update(['country_id' => $ecuadorId]);

        Schema::table('pricing_settings', function (Blueprint $table) {
            $table->foreignId('country_id')->nullable(false)->unique()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pricing_settings', function (Blueprint $table) {
            $table->dropForeign(['country_id']);
            $table->dropColumn('country_id');
        });
    }
};
