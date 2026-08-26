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
        Schema::table('site_settings', function (Blueprint $table) {
            // Pedido explícito del usuario: "en el admin permiteme en el
            // modulo de sistema de habilitar o no estas opciones del menu
            // tanto las del conductor como las del cliente" — array de
            // nombres de ruta apagados (ver quickLinks en
            // AuthenticatedLayout.vue). JSON en vez de una columna booleana
            // por ítem a propósito: la lista de accesos rápidos ya vive
            // como un array en el frontend y puede crecer con el tiempo —
            // una columna por ítem exigiría una migración nueva cada vez.
            // Nulo/vacío por defecto: nadie pierde ningún acceso de golpe.
            $table->json('disabled_quick_links')->nullable()->after('tiktok_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn('disabled_quick_links');
        });
    }
};
