<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sector de origen/destino (consideración agregada al alcance): además
     * del mapa, el cliente indica el sector de dónde sale y a dónde va (ej.
     * "Sauces 1" → "Samanes 3"), para que el conductor lo entienda de un
     * vistazo sin tener que abrir el mapa. `rides` repite las columnas para
     * guardar su propia "foto" al aceptar (mismo criterio que el resto de
     * columnas de origen/destino, sección 9.6).
     */
    public function up(): void
    {
        Schema::table('ride_requests', function (Blueprint $table) {
            $table->foreignId('origin_sector_id')->nullable()->after('origin_address')->constrained('sectors')->nullOnDelete();
            $table->foreignId('destination_sector_id')->nullable()->after('destination_address')->constrained('sectors')->nullOnDelete();
        });

        Schema::table('rides', function (Blueprint $table) {
            $table->foreignId('origin_sector_id')->nullable()->after('origin_address')->constrained('sectors')->nullOnDelete();
            $table->foreignId('destination_sector_id')->nullable()->after('destination_address')->constrained('sectors')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ride_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('origin_sector_id');
            $table->dropConstrainedForeignId('destination_sector_id');
        });

        Schema::table('rides', function (Blueprint $table) {
            $table->dropConstrainedForeignId('origin_sector_id');
            $table->dropConstrainedForeignId('destination_sector_id');
        });
    }
};
