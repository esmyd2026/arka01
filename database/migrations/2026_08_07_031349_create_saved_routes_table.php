<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Mis rutas" (pedido explícito del usuario): guardar un origen+destino
     * ya usados, con un alias opcional (ej. "Casa", "Trabajo"), para pedir
     * la próxima carrera sin volver a escribir ni marcar nada en el mapa.
     * Distinto de las "direcciones favoritas" (Ride/Request.vue, sacadas
     * automáticamente del historial) — acá es un PAR completo (origen Y
     * destino juntos) que el cliente guarda a propósito, con nombre propio.
     */
    public function up(): void
    {
        Schema::create('saved_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_user_id')->constrained('users')->cascadeOnDelete();

            // Opcional a propósito (pedido explícito del usuario: "que no sea
            // obligatorio") — sin alias, se identifica por las direcciones.
            $table->string('alias', 50)->nullable();

            $table->decimal('origin_lat', 10, 7);
            $table->decimal('origin_lng', 10, 7);
            $table->string('origin_address')->nullable();
            $table->foreignId('origin_sector_id')->nullable()->constrained('sectors')->nullOnDelete();

            $table->decimal('destination_lat', 10, 7);
            $table->decimal('destination_lng', 10, 7);
            $table->string('destination_address')->nullable();
            $table->foreignId('destination_sector_id')->nullable()->constrained('sectors')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_routes');
    }
};
