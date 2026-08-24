<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Activación manual de un conductor puntual (pedido explícito del
 * usuario: "permiteme colocar a un conductor activo asi no mande toda la
 * informacion. para que pueda operar. y se pueda poner disponible") — un
 * admin puede saltarse el requisito de documentos/seguro completos para UN
 * conductor concreto (ej. ya vetado por una cooperativa, cuenta de demo),
 * sin tocar el requisito para el resto. Ver
 * DriverProfile::hasCompleteRegistrationInformation() y
 * Admin\UserProfileController::forceActivate().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->timestamp('admin_activated_at')->nullable()->after('verified_by');
            $table->foreignId('admin_activated_by')->nullable()->after('admin_activated_at')->constrained('users')->nullOnDelete();
            $table->text('admin_activation_note')->nullable()->after('admin_activated_by');
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('admin_activated_by');
            $table->dropColumn(['admin_activated_at', 'admin_activation_note']);
        });
    }
};
