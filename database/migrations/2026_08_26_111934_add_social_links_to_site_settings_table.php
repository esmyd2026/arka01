<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pedido explícito del usuario ("le invite a seguir las redes") — links
     * opcionales, configurables desde /admin/sitio (mismo patrón que el
     * resto de SiteSetting), usados al agradecer una calificación por
     * WhatsApp. Ninguno es obligatorio: si el admin no completó alguno, esa
     * línea simplemente no se ofrece.
     */
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->string('facebook_url')->nullable();
            $table->string('instagram_url')->nullable();
            $table->string('tiktok_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn(['facebook_url', 'instagram_url', 'tiktok_url']);
        });
    }
};
