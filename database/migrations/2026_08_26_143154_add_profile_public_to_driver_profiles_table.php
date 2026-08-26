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
        Schema::table('driver_profiles', function (Blueprint $table) {
            // Pedido explícito del usuario ("mejoremos la privacidad de los
            // conductores"): distinto de `is_public` (que controla si
            // aparece en el DIRECTORIO buscable, gateado por plan) — esto
            // controla si su PERFIL INDIVIDUAL (profiles.show, visible hoy
            // sin excepción para cualquiera con el enlace, incluso sin
            // sesión) muestra los detalles reales o una versión bloqueada.
            // Default true: nadie pierde visibilidad de golpe al desplegar esto.
            $table->boolean('profile_public')->default(true)->after('is_public');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn('profile_public');
        });
    }
};
