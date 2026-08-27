<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bug reportado por el usuario: "se estan registrando como conductor y
     * el sistema termina creandole como [cliente]" — RegisteredUserController::store()
     * a propósito no persistía el rol elegido en el registro (el rol
     * "real" es isDriver()/isClient(), según exista o no un DriverProfile,
     * sección 3.1) y solo redirigía a completar el perfil de conductor. Si
     * la persona abandonaba ese segundo paso (cerró el navegador, no tenía
     * a mano los datos del vehículo), la cuenta quedaba funcionando como
     * cliente para siempre, sin ningún aviso — ver el nuevo middleware
     * EnsureDriverOnboardingIsComplete. Esta columna es solo esa señal de
     * "todavía le falta terminar" — no reemplaza a isDriver()/isClient()
     * como fuente de verdad del rol, que sigue siendo la existencia real
     * del DriverProfile.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('intends_to_drive')->default(false)->after('role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('intends_to_drive');
        });
    }
};
