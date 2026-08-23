<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Imagen de fondo del panel de marca en login/registro (pedido explícito
     * del usuario: "podemos mejorar el diseño del login también poder
     * colocar la imagen de fondo") — misma fila única de `site_settings`,
     * columna separada de `hero_background_path` porque son dos imágenes
     * independientes (una admin puede querer una sin la otra).
     */
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->string('auth_background_path')->nullable()->after('hero_background_path');
        });
    }

    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn('auth_background_path');
        });
    }
};
