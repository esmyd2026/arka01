<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido explícito del usuario: poder pasar de cliente a conductor (y de
 * vuelta) desde un botón del perfil, "fácil". La única forma que existía de
 * "dejar de ser conductor" era borrar por completo la fila de
 * `driver_profiles` — perdiendo vehículo, verificación y medallas para
 * siempre. `deactivated_at` es un "pausado" reversible propio del usuario
 * (distinto de `suspended_at`, que es moderación de un admin): mientras esté
 * puesto, `User::isDriver()` da false (la cuenta vuelve a operar como
 * cliente) pero todos los datos del conductor quedan guardados tal cual —
 * ver App\Http\Controllers\DriverProfileController::deactivate()/reactivate().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->timestamp('deactivated_at')->nullable()->after('suspended_at');
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn('deactivated_at');
        });
    }
};
