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
        // sos_alerts: registro de cada vez que alguien tocó el botón SOS
        // durante un viaje (sección 8). Guarda una "foto" de la ubicación y
        // los datos del conductor/vehículo en ese momento — igual que el resto
        // del sistema, para que cambios posteriores en el perfil no alteren
        // el registro de la emergencia (sección 9.6).
        Schema::create('sos_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_id')->constrained()->cascadeOnDelete();
            $table->foreignId('triggered_by')->constrained('users')->cascadeOnDelete();

            $table->string('driver_name');
            $table->string('driver_phone')->nullable();
            $table->string('vehicle_plate')->nullable();
            $table->string('vehicle_make')->nullable();
            $table->string('vehicle_model')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();

            // Cuántos contactos de confianza recibieron el correo, para saber
            // de un vistazo si la alerta realmente llegó a alguien.
            $table->unsignedInteger('notified_contacts_count')->default(0);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sos_alerts');
    }
};
