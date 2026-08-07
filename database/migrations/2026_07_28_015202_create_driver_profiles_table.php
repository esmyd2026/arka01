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
        // driver_profiles: existir en esta tabla es lo que convierte a un usuario en
        // "conductor" (sección 3.1 — un usuario puede ser cliente y/o conductor a la vez).
        Schema::create('driver_profiles', function (Blueprint $table) {
            $table->id();

            // Un usuario tiene, como máximo, un perfil de conductor.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Licencia y vehículo (sección 9.4 / módulo "Registro y verificación" del conductor).
            $table->string('license_number');
            $table->string('license_photo_path')->nullable();
            $table->string('vehicle_make')->nullable();
            $table->string('vehicle_model')->nullable();
            $table->string('vehicle_plate')->nullable();
            $table->unsignedSmallInteger('vehicle_year')->nullable();
            $table->string('vehicle_photo_path')->nullable();

            // Precio y forma de pago (sección 5 y 3.6): el conductor define su propia
            // tarifa por km y qué medios de pago acepta, la plataforma no impone precio.
            $table->decimal('rate_per_km', 8, 2)->default(0);
            $table->decimal('minimum_fare', 8, 2)->nullable();
            $table->boolean('accepts_cash')->default(true);
            $table->boolean('accepts_transfer')->default(false);

            // Disponibilidad para recibir carreras (sección 3.5). La ubicación en vivo se
            // suma en la fase de geolocalización/tiempo real, no en esta primera etapa.
            $table->boolean('is_available')->default(false);

            // Visibilidad en el directorio público de conductores (sección 3.4). Solo puede
            // quedar en true si el plan del conductor la incluye (sección 9.6); por ahora,
            // como no existe todavía el modelo de suscripciones, esa validación usa los
            // límites fijos del plan Gratis definidos en config/arka.php.
            $table->boolean('is_public')->default(false);

            // Verificación de identidad por un administrador antes de poder recibir
            // solicitudes (sección 9.7 "Seguridad").
            $table->enum('verification_status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();

            // Código corto para que un cliente invite a este conductor por código/QR
            // (sección 3.2 y 9.5) sin tener que buscarlo por nombre o teléfono.
            $table->string('invite_code', 12)->unique();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('driver_profiles');
    }
};
