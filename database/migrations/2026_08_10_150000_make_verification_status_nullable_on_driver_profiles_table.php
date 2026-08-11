<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Bug crítico reportado por el usuario: un conductor que guardaba su
        // perfil SIN subir ninguna foto terminaba con verification_status =
        // 'pending' igual, porque esa era la columna ENUM default — no
        // porque de verdad hubiera algo esperando revisión. Eso lo dejaba
        // bloqueado para subir sus documentos (DriverProfile::canUploadDocuments())
        // y, del lado admin, no aparecía en la cola de revisión (no tiene
        // license_photo_path) — el estado visible no correspondía a la
        // realidad. Se pasa de ENUM a string nullable sin default: ahora
        // 'pending' solo lo pone DriverProfileController::update() cuando
        // de verdad se subió un archivo (ver App\Http\Controllers\Admin\DriverVerificationController
        // para approved/rejected, que ya seteaban el valor a mano).
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->string('verification_status')->nullable()->default(null)->change();
        });

        // Corrige las filas ya afectadas por el bug: 'pending' puesto solo
        // por el default de la columna, sin ningún documento real subido.
        DB::table('driver_profiles')
            ->where('verification_status', 'pending')
            ->whereNull('license_photo_path')
            ->whereNull('vehicle_photo_path')
            ->update(['verification_status' => null]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->enum('verification_status', ['pending', 'approved', 'rejected'])->default('pending')->change();
        });
    }
};
